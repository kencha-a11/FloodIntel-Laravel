<?php

namespace App\Services;

use App\Enums\AlertLevel;
use App\Enums\FloodImpactStatus;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\SituationalReport;
use App\Models\SituationalReportSupplement;
use App\Models\User;
use App\Support\ConsolidationWindow;
use App\Support\SituationalReportSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation for the situational report (D-41, D-147, FR-75–FR-80).
 *
 * Reads through module 06's validated scope and module 06's consolidation
 * window; it defines neither. `aggregate()` is a pure read, and `prepare()`
 * writes the snapshot and its provenance in one transaction. When an event
 * is selected, the adapted document's supplement drafts and cumulative-event
 * impact filings are frozen into the snapshot's content at prepare time.
 */
final class SituationalReportAggregator
{
    /**
     * The inclusion rule's window membership for status counts. It mirrors the
     * deadline endpoint: an observation instant, with the receipt time as the
     * stated fallback when the device never supplied one.
     *
     * @return Builder<FloodReport>
     */
    private function windowedReports(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return FloodReport::query()->where(function (Builder $query) use ($start, $end): void {
            $query->where(function (Builder $query) use ($start, $end): void {
                $query->whereNotNull('reported_at')
                    ->where('reported_at', '>=', $start)
                    ->where('reported_at', '<', $end);
            })->orWhere(function (Builder $query) use ($start, $end): void {
                $query->whereNull('reported_at')
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end);
            });
        });
    }

    /**
     * The latest validation instant per report, from the append-only audit.
     *
     * @return Builder<FloodReportStatusChange>
     */
    private function latestValidation(CarbonImmutable $snapshot): Builder
    {
        return FloodReportStatusChange::query()
            ->selectRaw('flood_report_id, MAX(created_at) AS validated_at')
            ->where('to_status', FloodReportStatus::Validated->value)
            ->where('created_at', '<=', $snapshot)
            ->groupBy('flood_report_id');
    }

    /**
     * Reports included by D-41: validated at the snapshot instant, validated
     * at or before it, and observed inside the window.
     *
     * @return Collection<int, FloodReport>
     */
    private function includedReports(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $snapshot): Collection
    {
        return FloodReport::query()
            ->validated()
            ->joinSub($this->latestValidation($snapshot), 'latest_validation', 'latest_validation.flood_report_id', '=', 'flood_reports.id')
            ->whereNotNull('flood_reports.reported_at')
            ->where('flood_reports.reported_at', '>=', $start)
            ->where('flood_reports.reported_at', '<', $end)
            ->orderBy('flood_reports.reported_at')
            ->orderBy('flood_reports.id')
            ->withCasts(['validated_at' => 'immutable_datetime'])
            ->get([
                'flood_reports.id as flood_report_id',
                'flood_reports.barangay_id',
                'flood_reports.alert_level',
                'flood_reports.flood_level',
                'flood_reports.reported_at',
                'flood_reports.created_at',
                'latest_validation.validated_at',
            ]);
    }

    /**
     * Validated reports that belong to the window by receipt time but cannot
     * enter the figures because no observation instant exists (FR-28).
     */
    private function nullObservationCount(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $snapshot): int
    {
        return FloodReport::query()
            ->validated()
            ->joinSub($this->latestValidation($snapshot), 'latest_validation', 'latest_validation.flood_report_id', '=', 'flood_reports.id')
            ->whereNull('flood_reports.reported_at')
            ->where('flood_reports.created_at', '>=', $start)
            ->where('flood_reports.created_at', '<', $end)
            ->count();
    }

    /**
     * @param  Builder<FloodReport>  $windowed
     * @return array<int, array{validated: int, pending: int, returned: int, rejected: int}>
     */
    private function statusCountsByBarangay(Builder $windowed): array
    {
        $counts = [];

        $rows = $windowed
            ->selectRaw('barangay_id, status, count(*) AS aggregate')
            ->groupBy('barangay_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $barangayId = (int) $row->barangay_id;
            $counts[$barangayId] ??= self::emptyStatusCounts();

            $key = match ($row->status) {
                FloodReportStatus::Submitted => 'pending',
                FloodReportStatus::Validated => 'validated',
                FloodReportStatus::Returned => 'returned',
                FloodReportStatus::Rejected => 'rejected',
            };

            $counts[$barangayId][$key] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return array{validated: int, pending: int, returned: int, rejected: int}
     */
    private static function emptyStatusCounts(): array
    {
        return ['validated' => 0, 'pending' => 0, 'returned' => 0, 'rejected' => 0];
    }

    /**
     * @param  Collection<int, FloodReport>  $reports
     */
    private function highestAlert(Collection $reports): ?string
    {
        $highest = null;
        $severity = 0;

        foreach ($reports as $report) {
            if ($report->alert_level instanceof AlertLevel && $report->alert_level->severity() > $severity) {
                $severity = $report->alert_level->severity();
                $highest = $report->alert_level->value;
            }
        }

        return $highest;
    }

    /**
     * @param  Collection<int, FloodReport>  $reports
     */
    private function highestFlood(Collection $reports): ?string
    {
        $highest = null;
        $severity = 0;

        foreach ($reports as $report) {
            if ($report->flood_level instanceof FloodLevel && $report->flood_level->severity() > $severity) {
                $severity = $report->flood_level->severity();
                $highest = $report->flood_level->value;
            }
        }

        return $highest;
    }

    /**
     * @param  list<int>  $samples
     * @return array{samples: int, excluded_null_observation: int, mean_seconds: float|null, median_seconds: float|null, max_seconds: int|null}
     */
    private function latencyStatistics(array $samples, int $excluded): array
    {
        sort($samples);
        $count = count($samples);

        if ($count === 0) {
            return [
                'samples' => 0,
                'excluded_null_observation' => $excluded,
                'mean_seconds' => null,
                'median_seconds' => null,
                'max_seconds' => null,
            ];
        }

        $middle = intdiv($count, 2);

        return [
            'samples' => $count,
            'excluded_null_observation' => $excluded,
            'mean_seconds' => round(array_sum($samples) / $count, 1),
            'median_seconds' => $count % 2 === 1
                ? (float) $samples[$middle]
                : round(($samples[$middle - 1] + $samples[$middle]) / 2, 1),
            'max_seconds' => max($samples),
        ];
    }

    /**
     * The latest non-superseded impact filing per barangay for the event as
     * of the snapshot (D-147): cumulative for the event, never a per-cutoff
     * sum. Ties on the observation time resolve to the higher id.
     *
     * @return Collection<int, FloodImpactReport>
     */
    private function latestImpactFilings(int $eventId, CarbonImmutable $snapshot): Collection
    {
        return FloodImpactReport::query()
            ->where('event_id', $eventId)
            ->where('status', FloodImpactStatus::Submitted)
            ->where('reported_at', '<=', $snapshot)
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get(['id', 'barangay_id', 'families', 'individuals', 'reported_at'])
            ->unique('barangay_id');
    }

    /**
     * Freeze the event's reviewed supplement drafts into document content.
     * Later draft edits affect only future revisions (D-40, D-147).
     *
     * @return array{event: array<string, mixed>|null, supplements: list<array<string, mixed>>, adaptation: string}
     */
    private function documentContent(?FloodEvent $event): array
    {
        $supplements = $event === null
            ? collect()
            : SituationalReportSupplement::query()
                ->where('event_id', $event->id)
                ->with('barangay:id,name')
                ->orderBy('id')
                ->get();

        return [
            'event' => $event === null ? null : [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'starts_at' => $event->starts_at?->utc()->toIso8601String(),
                'ends_at' => $event->ends_at?->utc()->toIso8601String(),
                'is_closed' => $event->is_closed,
            ],
            'supplements' => $supplements->map(fn (SituationalReportSupplement $row): array => [
                'id' => $row->id,
                'section' => $row->section->value,
                'barangay_id' => $row->barangay_id,
                'barangay' => $row->barangay?->name,
                'payload' => $row->payload,
                'source' => $row->source,
                'observed_at' => $row->observed_at?->utc()->toIso8601String(),
                'created_by_name' => $row->created_by_name,
            ])->values()->all(),
            'adaptation' => 'Aggregate-only adaptation of the PDRRMO SITREP template (D-45, amended 2026-11-18): barangay-level figures only; no individual identities, addresses, coordinates, or photographs.',
        ];
    }

    /**
     * Compute the document's figures without writing anything. With an event
     * selected, impact figures and frozen supplement content are included
     * (D-147); without one, impact stays unavailable and content is null.
     */
    public function aggregate(ConsolidationWindow $window, ?CarbonInterface $snapshotAt = null, ?int $eventId = null): SituationalReportSnapshot
    {
        $start = $window->startsAt->utc();
        $end = $window->nextCutoffAt->utc();
        $snapshot = CarbonImmutable::instance($snapshotAt ?? $window->now)->utc();

        $windowed = $this->windowedReports($start, $end);
        $basis = (clone $windowed)->whereNull('reported_at')->exists()
            ? 'reported_at_with_created_at_fallback'
            : 'reported_at';

        $counts = $this->statusCountsByBarangay($windowed);
        $included = $this->includedReports($start, $end, $snapshot);
        $excluded = $this->nullObservationCount($start, $end, $snapshot);

        $includedByBarangay = $included->groupBy('barangay_id');
        $event = $eventId === null ? null : FloodEvent::query()->find($eventId);
        $impact = $event === null ? collect() : $this->latestImpactFilings($event->id, $snapshot)->keyBy('barangay_id');
        $barangays = [];
        $includedReports = [];

        foreach (Barangay::query()->orderBy('code')->get(['id', 'name', 'code']) as $barangay) {
            $barangayCounts = $counts[$barangay->id] ?? self::emptyStatusCounts();
            $barangayIncluded = $includedByBarangay->get($barangay->id, collect());
            $reports = array_sum($barangayCounts);

            $barangays[] = [
                'barangay_id' => (int) $barangay->id,
                'barangay' => $barangay->name,
                'barangay_code' => $barangay->code,
                'no_report_received' => $reports === 0,
                'reports' => $reports,
                'included_reports' => $barangayIncluded->count(),
                'status_counts' => $barangayCounts,
                'alert_level' => $this->highestAlert($barangayIncluded),
                'flood_level' => $this->highestFlood($barangayIncluded),
                'affected_families' => $impact->get($barangay->id)?->families,
                'affected_individuals' => $impact->get($barangay->id)?->individuals,
            ];

            foreach ($barangayIncluded as $report) {
                $includedReports[] = [
                    'flood_report_id' => (int) $report->flood_report_id,
                    'barangay_id' => (int) $report->barangay_id,
                    'alert_level' => $report->alert_level?->value,
                    'flood_level' => $report->flood_level?->value,
                    'observed_at' => CarbonImmutable::instance($report->reported_at)->utc(),
                    'validated_at' => CarbonImmutable::instance($report->validated_at)->utc(),
                ];
            }
        }

        $statusTotals = self::emptyStatusCounts();

        foreach ($counts as $barangayCounts) {
            foreach ($barangayCounts as $status => $count) {
                $statusTotals[$status] += $count;
            }
        }

        $latencies = $included
            ->map(fn (FloodReport $report): int => $report->created_at->getTimestamp() - $report->reported_at->getTimestamp())
            ->values()
            ->all();

        return new SituationalReportSnapshot(
            windowStartsAt: $start,
            windowEndsAt: $end,
            snapshotAt: $snapshot,
            cutoffLabel: $window->label(),
            timezone: $window->timezone,
            statusCounts: ['basis' => $basis] + $statusTotals,
            totals: [
                'reports' => array_sum(array_map('array_sum', $counts)),
                'included_reports' => $included->count(),
                'alert_level' => $this->highestAlert($included),
                'flood_level' => $this->highestFlood($included),
            ],
            latency: $this->latencyStatistics($latencies, $excluded),
            barangays: $barangays,
            includedReports: $includedReports,
            stageTwo: $event === null ? [
                'available' => false,
                'reason' => SituationalReportSnapshot::NO_EVENT_REASON,
                'affected_families' => null,
                'affected_individuals' => null,
            ] : [
                'available' => true,
                'basis' => SituationalReportSnapshot::IMPACT_BASIS,
                'reason' => SituationalReportSnapshot::IMPACT_REASON,
                'affected_families' => $impact->isEmpty() ? null : (int) $impact->sum('families'),
                'affected_individuals' => $impact->isEmpty() ? null : (int) $impact->sum('individuals'),
            ],
            eventId: $event?->id,
            content: $event === null ? null : $this->documentContent($event),
        );
    }

    /**
     * Write one immutable revision of the current snapshot (D-40, FR-81).
     * Staff prepare via ConsolidateReports; the officer may also prepare
     * on demand via GenerateProvincialReport (D-147).
     */
    public function prepare(ConsolidationWindow $window, User $generatedBy, ?CarbonInterface $snapshotAt = null, ?int $eventId = null): SituationalReport
    {
        if (! $generatedBy->hasAbility(UserAbility::ConsolidateReports)
            && ! $generatedBy->hasAbility(UserAbility::GenerateProvincialReport)) {
            throw new AuthorizationException('Preparing a situational report requires the '.UserAbility::ConsolidateReports->label().' or '.UserAbility::GenerateProvincialReport->label().' ability.');
        }

        $snapshot = $this->aggregate($window, $snapshotAt, $eventId);
        $cutoff = $snapshot->windowEndsAt;

        return DB::transaction(function () use ($snapshot, $generatedBy, $cutoff): SituationalReport {
            // No row lock: app_runtime has INSERT but no UPDATE, and a
            // SELECT ... FOR UPDATE requires UPDATE privilege. The unique
            // (cutoff_at, revision) key is the concurrency guard instead.
            $previous = SituationalReport::query()
                ->where('cutoff_at', $cutoff)
                ->orderByDesc('revision')
                ->first();

            $report = SituationalReport::query()->create([
                'window_starts_at' => $snapshot->windowStartsAt,
                'window_ends_at' => $snapshot->windowEndsAt,
                'cutoff_at' => $cutoff,
                'cutoff_label' => $snapshot->cutoffLabel,
                'timezone' => $snapshot->timezone,
                'snapshot_at' => $snapshot->snapshotAt,
                'revision' => ($previous?->revision ?? 0) + 1,
                'predecessor_id' => $previous?->id,
                'format_version' => SituationalReportSnapshot::FORMAT_VERSION,
                'generated_by_user_id' => $generatedBy->id,
                'generated_by_name' => $generatedBy->full_name,
                'generated_by_role' => $generatedBy->role->value,
                'status_counts' => $snapshot->statusCounts,
                'totals' => $snapshot->totals,
                'barangays' => $snapshot->barangays,
                'latency' => $snapshot->latency,
                'stage_two' => $snapshot->stageTwo,
                'event_id' => $snapshot->eventId,
                'content' => $snapshot->content,
            ]);

            foreach ($snapshot->includedReports as $row) {
                $report->inclusions()->create([
                    'flood_report_id' => $row['flood_report_id'],
                    'barangay_id' => $row['barangay_id'],
                    'alert_level' => $row['alert_level'],
                    'flood_level' => $row['flood_level'],
                    'observed_at' => $row['observed_at'],
                    'validated_at' => $row['validated_at'],
                ]);
            }

            return $report->load('inclusions');
        });
    }
}

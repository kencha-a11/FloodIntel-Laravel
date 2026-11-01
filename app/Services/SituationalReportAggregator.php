<?php

namespace App\Services;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\SituationalReport;
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
 * Stage-1 aggregation for the situational report (D-41, FR-75–FR-80).
 *
 * Reads through module 06's validated scope and module 06's consolidation
 * window; it defines neither. `aggregate()` is a pure read, and `prepare()`
 * writes the snapshot and its provenance in one transaction.
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
     * Compute the document's stage-1 figures without writing anything.
     */
    public function aggregate(ConsolidationWindow $window, ?CarbonInterface $snapshotAt = null): SituationalReportSnapshot
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
                'affected_families' => null,
                'affected_individuals' => null,
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
            stageTwo: [
                'available' => false,
                'reason' => SituationalReportSnapshot::STAGE_TWO_REASON,
                'affected_families' => null,
                'affected_individuals' => null,
            ],
        );
    }

    /**
     * Write one immutable revision of the current snapshot (D-40, FR-81).
     */
    public function prepare(ConsolidationWindow $window, User $generatedBy, ?CarbonInterface $snapshotAt = null): SituationalReport
    {
        if (! $generatedBy->hasAbility(UserAbility::ConsolidateReports)) {
            throw new AuthorizationException('Preparing a situational report requires the '.UserAbility::ConsolidateReports->label().' ability.');
        }

        $snapshot = $this->aggregate($window, $snapshotAt);
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

<?php

namespace App\Console\Commands;

use App\Enums\UserAbility;
use App\Models\FloodEvent;
use App\Models\SituationalReportRun;
use App\Models\User;
use App\Services\SituationalReportAggregator;
use App\Support\ConsolidationWindow;
use App\Support\DatabaseIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prepare the SITREP for a just-closed cutoff (D-44, D-147, G7).
 *
 * The schedule passes the intended cutoff explicitly, so a delayed run
 * still snapshots the window that closed — never the new one — and the
 * snapshot instant stays the actual run time (no backdating). Every attempt
 * appends a run row; a cutoff already prepared is skipped, so a retry or a
 * second scheduler never duplicates the output.
 */
class PrepareSitrepCutoff extends Command
{
    protected $signature = 'sitrep:prepare-cutoff
        {cutoff : The cutoff that just closed, as HH:MM in the deadlines timezone}
        {--trigger=schedule : schedule or manual (on-demand recovery)}';

    protected $description = 'Prepare the situational report for a just-closed consolidation cutoff';

    public function handle(SituationalReportAggregator $aggregator): int
    {
        $timezone = (string) config('deadlines.timezone', 'Asia/Manila');
        $cutoffs = array_map('strval', (array) config('deadlines.cutoffs', ['04:00', '15:00']));
        $requested = (string) $this->argument('cutoff');

        if (! in_array($requested, $cutoffs, true)) {
            $this->error("Unknown cutoff {$requested}; configured cutoffs: ".implode(', ', $cutoffs).'.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $requested));
        $cutoff = $now->startOfDay()->setTime($hour, $minute);

        if ($cutoff->greaterThan($now)) {
            $this->error("The {$requested} cutoff has not passed yet; nothing to prepare.");

            return self::FAILURE;
        }

        $trigger = (string) $this->option('trigger');
        $window = ConsolidationWindow::current($cutoff->subSecond());

        $alreadyPrepared = SituationalReportRun::query()
            ->where('cutoff_at', $cutoff->utc())
            ->where('state', 'prepared')
            ->exists();

        if ($alreadyPrepared) {
            $this->info("The {$requested} cutoff already has a prepared snapshot; skipping.");

            return self::SUCCESS;
        }

        $schedulerId = config('sitrep.scheduler_user_id');
        $scheduler = $schedulerId === null ? null : User::query()->find($schedulerId);

        if ($scheduler === null || ! $scheduler->hasAbility(UserAbility::ConsolidateReports)) {
            $reason = $scheduler === null
                ? 'No scheduler principal is configured (SITREP_SCHEDULER_USER_ID); the cutoff was not prepared.'
                : 'The configured scheduler principal cannot prepare situational reports.';

            $this->recordRun($cutoff, $window, 'failed', $reason, null, $trigger, null);
            $this->error($reason);

            return self::FAILURE;
        }

        // The event the scheduled document covers: the newest open event, or
        // no event (stage-1 figures only) when none is open.
        $event = FloodEvent::query()->where('is_closed', false)->orderByDesc('starts_at')->first();

        try {
            $report = DatabaseIdentity::runAs($scheduler->id, function () use ($aggregator, $window, $scheduler, $event, $cutoff, $trigger): mixed {
                return DB::transaction(function () use ($aggregator, $window, $scheduler, $event, $cutoff, $trigger) {
                    $report = $aggregator->prepare($window, $scheduler, CarbonImmutable::now(), $event?->id);

                    $this->recordRun($cutoff, $window, 'prepared', null, $report->id, $trigger, $scheduler);

                    return $report;
                });
            });

            $this->info("Prepared revision {$report->revision} for the {$requested} cutoff.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // The failure record carries the same identity as the attempt so
            // the append-only insert policy accepts it on Postgres.
            DatabaseIdentity::runAs($scheduler->id, function () use ($cutoff, $window, $exception, $trigger, $scheduler): void {
                $this->recordRun($cutoff, $window, 'failed', $exception->getMessage(), null, $trigger, $scheduler);
            });
            $this->error('Preparation failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function recordRun(
        CarbonImmutable $cutoff,
        ConsolidationWindow $window,
        string $state,
        ?string $reason,
        ?int $reportId,
        string $trigger,
        ?User $actor,
    ): void {
        SituationalReportRun::query()->create([
            'cutoff_at' => $cutoff->utc(),
            'window_starts_at' => $window->startsAt->utc(),
            'state' => $state,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 1000),
            'situational_report_id' => $reportId,
            'triggered_by' => $trigger,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->full_name,
        ]);
    }
}

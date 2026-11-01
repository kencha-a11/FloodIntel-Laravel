<?php

namespace App\Services;

use App\Enums\FloodReportStatus;
use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\FloodReportStatusChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of FloodReport::status (D-22).
 *
 * Every change lands with its audit row in one transaction. A caller that
 * assigns the status directly is stopped by the model guard. Re-opening a
 * decided report is the override path, so validate/return/reject only accept
 * a submitted report (D-23).
 */
final class FloodReportTransitions
{
    /**
     * D-21's transition table.
     *
     * @var array<string, list<FloodReportStatus>>
     */
    private const ALLOWED = [
        'submitted' => [FloodReportStatus::Validated, FloodReportStatus::Returned, FloodReportStatus::Rejected],
        'validated' => [FloodReportStatus::Returned, FloodReportStatus::Rejected],
        'returned' => [FloodReportStatus::Validated, FloodReportStatus::Rejected],
        'rejected' => [],
    ];

    private static bool $writing = false;

    /**
     * Whether a service write is in flight; the model guard reads this.
     */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * Record the creation of a report as its initial `submitted` row.
     */
    public function submit(FloodReport $report, User $actor): FloodReportStatusChange
    {
        return DB::transaction(fn (): FloodReportStatusChange => $this->record(
            $report,
            $actor,
            null,
            FloodReportStatus::Submitted,
            null,
        ));
    }

    public function validate(FloodReport $report, User $actor): FloodReportStatusChange
    {
        $this->authorize($actor, UserAbility::ValidateReport);

        return $this->move($report, $actor, FloodReportStatus::Validated, null, [FloodReportStatus::Submitted]);
    }

    public function return(FloodReport $report, User $actor, string $reason): FloodReportStatusChange
    {
        $this->authorize($actor, UserAbility::ValidateReport);

        return $this->move(
            $report,
            $actor,
            FloodReportStatus::Returned,
            $this->requireReason($reason),
            [FloodReportStatus::Submitted],
        );
    }

    public function reject(FloodReport $report, User $actor, string $reason): FloodReportStatusChange
    {
        $this->authorize($actor, UserAbility::ValidateReport);

        return $this->move(
            $report,
            $actor,
            FloodReportStatus::Rejected,
            $this->requireReason($reason),
            [FloodReportStatus::Submitted],
        );
    }

    /**
     * Reverse a decision already made on the report.
     */
    public function override(FloodReport $report, User $actor, FloodReportStatus $to, string $reason): FloodReportStatusChange
    {
        $this->authorize($actor, UserAbility::OverrideValidation);

        return $this->move(
            $report,
            $actor,
            $to,
            $this->requireReason($reason),
            [FloodReportStatus::Validated, FloodReportStatus::Returned],
        );
    }

    /**
     * @param  list<FloodReportStatus>  $sources
     */
    private function move(
        FloodReport $report,
        User $actor,
        FloodReportStatus $to,
        ?string $reason,
        array $sources,
    ): FloodReportStatusChange {
        return DB::transaction(function () use ($report, $actor, $to, $reason, $sources): FloodReportStatusChange {
            $from = $report->status;

            $this->assertLegal($from, $to, $sources);

            $attributes = ['status' => $to];

            if ($to === FloodReportStatus::Rejected) {
                $attributes['rejected_at'] = now();
                $attributes['rejected_by'] = $actor->id;
                $attributes['rejection_reason'] = $reason;
            }

            self::$writing = true;

            try {
                $report->forceFill($attributes)->save();
            } finally {
                self::$writing = false;
            }

            return $this->record($report, $actor, $from, $to, $reason);
        });
    }

    /**
     * @param  list<FloodReportStatus>  $sources
     */
    private function assertLegal(FloodReportStatus $from, FloodReportStatus $to, array $sources): void
    {
        if (! in_array($from, $sources, true) || ! in_array($to, self::ALLOWED[$from->value], true)) {
            throw ValidationException::withMessages([
                'status' => "A report cannot move from {$from->label()} to {$to->label()} through this action.",
            ]);
        }
    }

    private function record(
        FloodReport $report,
        User $actor,
        ?FloodReportStatus $from,
        FloodReportStatus $to,
        ?string $reason,
    ): FloodReportStatusChange {
        return $report->statusChanges()->create([
            'user_id' => $actor->id,
            'actor_name' => $actor->full_name,
            'actor_role' => $actor->role->value,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
        ]);
    }

    private function authorize(User $actor, UserAbility $ability): void
    {
        if (! $actor->hasAbility($ability)) {
            throw new AuthorizationException("This action requires the {$ability->label()} ability.");
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return $reason;
    }
}

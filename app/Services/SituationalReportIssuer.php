<?php

namespace App\Services;

use App\Enums\UserAbility;
use App\Models\SituationalReport;
use App\Models\SituationalReportIssuance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Officer issuance of a frozen snapshot (D-42, D-147).
 *
 * Issuance appends a record naming the officer, the per-event report number
 * (G3: system-assigned, separate from the revision number), and the office
 * metadata confirmed at issue (G4). It never updates the snapshot; a
 * correction is a new revision with its own issuance. The receiving office's
 * dated acceptance of the aggregate-only adaptation (G8) is an external
 * process gate recorded in the blueprint, not a code path.
 */
final class SituationalReportIssuer
{
    /**
     * @param  array<string, mixed>  $office
     */
    public function issue(SituationalReport $report, User $officer, array $office): SituationalReportIssuance
    {
        if (! $officer->hasAbility(UserAbility::GenerateProvincialReport)) {
            throw new AuthorizationException('Issuing a situational report requires the '.UserAbility::GenerateProvincialReport->label().' ability.');
        }

        if ($report->event_id === null) {
            throw ValidationException::withMessages([
                'event_id' => 'A revision must be linked to a flood event before it can be issued.',
            ]);
        }

        if ($report->issuance()->exists()) {
            throw ValidationException::withMessages([
                'situational_report_id' => 'This revision is already issued; prepare a new revision to correct it.',
            ]);
        }

        try {
            return DB::transaction(function () use ($report, $officer, $office): SituationalReportIssuance {
                // The unique (event_id, report_number) key is the concurrency
                // guard; app_runtime has no UPDATE privilege for a row lock.
                $next = (int) SituationalReportIssuance::query()
                    ->where('event_id', $report->event_id)
                    ->max('report_number') + 1;

                return SituationalReportIssuance::query()->create([
                    'situational_report_id' => $report->id,
                    'event_id' => $report->event_id,
                    'report_number' => $next,
                    'issued_by_user_id' => $officer->id,
                    'issued_by_name' => $officer->full_name,
                    'issued_by_role' => $officer->role->value,
                    'office' => $office,
                    'issued_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'situational_report_id' => 'Another issuance won the race for this revision or report number; reload and retry.',
            ]);
        }
    }
}

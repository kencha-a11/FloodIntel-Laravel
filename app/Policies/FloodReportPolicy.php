<?php

namespace App\Policies;

use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\User;

class FloodReportPolicy
{
    /**
     * Determine whether the user can view the report.
     */
    public function view(User $user, FloodReport $floodReport): bool
    {
        if ($user->hasAbility(UserAbility::ViewAllBarangays)) {
            return true;
        }

        if ($user->hasAbility(UserAbility::ViewOwnBarangay)) {
            return $floodReport->barangay_id === $user->barangay_id;
        }

        return $floodReport->user_id === $user->id;
    }

    /**
     * Determine whether the user can create reports.
     */
    public function create(User $user): bool
    {
        return $user->hasAbility(UserAbility::SubmitFloodReport);
    }

    /**
     * Determine whether the user can read the validated consumption feed (FR-24, FR-48).
     */
    public function viewValidatedFeed(User $user): bool
    {
        return $user->hasAbility(UserAbility::ViewValidatedReports);
    }

    /**
     * Determine whether the user can export the report log (FR-29).
     *
     * The export is the command center's cross-barangay review surface, so it
     * follows the same ability as the queue's cross-barangay filter.
     */
    public function export(User $user): bool
    {
        return $user->hasAbility(UserAbility::ViewAllBarangays);
    }

    /**
     * Determine whether the user can reject the report (D-23, FR-108).
     *
     * The officer-only restriction is the route group's; deciding at all is
     * the validation ability's.
     */
    public function reject(User $user, FloodReport $floodReport): bool
    {
        return $user->hasAbility(UserAbility::ValidateReport);
    }

    /**
     * Determine whether the user can validate the report (D-23).
     */
    public function validate(User $user, FloodReport $floodReport): bool
    {
        return $user->hasAbility(UserAbility::ValidateReport);
    }

    /**
     * Determine whether the user can return the report for correction (D-23).
     */
    public function return(User $user, FloodReport $floodReport): bool
    {
        return $user->hasAbility(UserAbility::ValidateReport);
    }

    /**
     * Determine whether the user can override a decision (D-23, FR-25).
     */
    public function override(User $user, FloodReport $floodReport): bool
    {
        return $user->hasAbility(UserAbility::OverrideValidation);
    }
}

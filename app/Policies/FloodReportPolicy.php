<?php

namespace App\Policies;

use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\User;

class FloodReportPolicy
{
    /**
     * Determine whether the user can view any reports.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAbility(UserAbility::SubmitFloodReport)
            || $user->hasAbility(UserAbility::ViewAllBarangays)
            || $user->hasAbility(UserAbility::ViewOwnBarangay);
    }

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
     * Determine whether the user can reject the report.
     */
    public function reject(User $user, FloodReport $floodReport): bool
    {
        return $user->hasAbility(UserAbility::ManageUsers);
    }
}

<?php

namespace App\Policies;

use App\Enums\UserAbility;
use App\Models\User;

class FloodImpactReportPolicy
{
    /**
     * Determine whether the user can file an impact report (TR-08, FR-02).
     *
     * A captain without a barangay is refused in the controller before any
     * side effect (FR-107); the barangay itself is never read from the payload.
     */
    public function create(User $user): bool
    {
        return $user->hasAbility(UserAbility::SubmitImpactReport);
    }
}

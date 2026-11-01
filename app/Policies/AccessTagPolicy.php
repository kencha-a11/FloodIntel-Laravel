<?php

namespace App\Policies;

use App\Enums\UserAbility;
use App\Models\AccessTag;
use App\Models\User;

class AccessTagPolicy
{
    /**
     * Determine whether the user can view the tag (FR-119).
     *
     * The same three branches as `FloodReportPolicy@view`: the command center
     * reads every barangay, a barangay-scoped account reads its own, and
     * anyone else reads only what it recorded.
     */
    public function view(User $user, AccessTag $accessTag): bool
    {
        if ($user->hasAbility(UserAbility::ViewAllBarangays)) {
            return true;
        }

        if ($user->hasAbility(UserAbility::ViewOwnBarangay)) {
            return $accessTag->barangay_id === $user->barangay_id;
        }

        return $accessTag->created_by === $user->id;
    }

    /**
     * Determine whether the user can record a tag (FR-118, D-128).
     */
    public function create(User $user): bool
    {
        return $user->hasAbility(UserAbility::RecordAccessTag);
    }

    /**
     * Determine whether the user can retire a tag (FR-118, FR-121, D-131).
     *
     * Retirement is the recording account's own act or a `ManageUsers`
     * holder's — not a second ability and not the wider barangay read.
     */
    public function retire(User $user, AccessTag $accessTag): bool
    {
        return $accessTag->created_by === $user->id
            || $user->hasAbility(UserAbility::ManageUsers);
    }
}

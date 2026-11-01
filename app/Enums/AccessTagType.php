<?php

namespace App\Enums;

/**
 * The closed set of constraints an access tag records (FR-112, D-127).
 *
 * The subject is a place, never a person (D-124): every value describes how a
 * route is hard to pass, not who lives behind it.
 */
enum AccessTagType: string
{
    case NarrowAlley = 'narrow_alley';
    case NoVehicleAccess = 'no_vehicle_access';
    case StairsOrSteep = 'stairs_or_steep';
    case StructurallyBlocked = 'structurally_blocked';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NarrowAlley => 'Narrow alley — foot carry only',
            self::NoVehicleAccess => 'No vehicle access',
            self::StairsOrSteep => 'Stairs or steep path',
            self::StructurallyBlocked => 'Structurally blocked',
            self::Other => 'Other constraint',
        };
    }

    /**
     * Whether the type needs a note to be intelligible (FR-112).
     */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}

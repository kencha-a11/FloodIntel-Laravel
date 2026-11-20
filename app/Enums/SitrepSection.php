<?php

namespace App\Enums;

/**
 * The reviewed supplement sections of the adapted SITREP template (D-147).
 * Each section maps to one table or block of the supplied PDRRMO template;
 * `Lifeline` rows carry a `kind` naming subsections i–iv (Water Supply is
 * the owner-named fourth subsection).
 */
enum SitrepSection: string
{
    case Weather = 'weather';
    case Station = 'station';
    case Lifeline = 'lifeline';
    case Evacuation = 'evacuation';
    case Evacuees = 'evacuees';
    case Households = 'households';
    case Casualties = 'casualties';
    case DamagedHouses = 'damaged_houses';
    case DamageCosts = 'damage_costs';
    case OtherIncidents = 'other_incidents';
    case Assistance = 'assistance';
    case Actions = 'actions';

    public function label(): string
    {
        return match ($this) {
            self::Weather => 'Weather condition',
            self::Station => 'River basin assessment',
            self::Lifeline => 'Lifelines',
            self::Evacuation => 'Pre-emptive evacuation',
            self::Evacuees => 'Evacuees',
            self::Households => 'Affected households',
            self::Casualties => 'Casualties (aggregate)',
            self::DamagedHouses => 'Damaged houses',
            self::DamageCosts => 'Cost of damages',
            self::OtherIncidents => 'Other incidents monitored',
            self::Assistance => 'Assistance extended',
            self::Actions => 'Actions taken',
        };
    }

    /**
     * Sections whose rows are scoped to one barangay.
     */
    public function isBarangayScoped(): bool
    {
        return match ($this) {
            self::Evacuation, self::Evacuees, self::Households, self::Casualties, self::DamagedHouses => true,
            default => false,
        };
    }
}

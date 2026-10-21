<?php

namespace App\Enums;

enum UserRole: string
{
    case MdrrmoOfficer = 'mdrrmo_officer';
    case MdrrmoStaff = 'mdrrmo_staff';
    case BarangayCaptain = 'barangay_captain';
    case BarangayTanod = 'barangay_tanod';
    case RescueLeader = 'rescue_leader';

    public function label(): string
    {
        return match ($this) {
            self::MdrrmoOfficer => 'MDRRMO Officer / Department Head',
            self::MdrrmoStaff => 'MDRRMO Admin / Operations Staff',
            self::BarangayCaptain => 'Barangay Captain',
            self::BarangayTanod => 'Barangay Tanod / BDRRMC Focal Person',
            self::RescueLeader => 'Emergency Response & Rescue Team Leader',
        };
    }

    public function interface(): AccessInterface
    {
        return match ($this) {
            self::MdrrmoOfficer, self::MdrrmoStaff => AccessInterface::CommandCenter,
            self::BarangayCaptain, self::BarangayTanod, self::RescueLeader => AccessInterface::FieldReporter,
        };
    }

    /**
     * The capabilities granted to this role.
     *
     * @return array<int, UserAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::MdrrmoOfficer => [
                UserAbility::ValidateReport,
                UserAbility::GenerateProvincialReport,
                UserAbility::MonitorDashboard,
                UserAbility::ManageUsers,
                UserAbility::ViewAllBarangays,
                UserAbility::ViewValidatedReports,
            ],
            self::MdrrmoStaff => [
                UserAbility::ConsolidateReports,
                UserAbility::DispatchAssets,
                UserAbility::MonitorDashboard,
                UserAbility::ViewAllBarangays,
                UserAbility::ViewValidatedReports,
            ],
            self::BarangayCaptain => [
                UserAbility::SubmitImpactReport,
                UserAbility::SubmitFloodReport,
                UserAbility::ViewOwnBarangay,
            ],
            self::BarangayTanod => [
                UserAbility::SubmitFloodObservation,
                UserAbility::SubmitFloodReport,
                UserAbility::ViewOwnBarangay,
            ],
            self::RescueLeader => [
                UserAbility::ViewValidatedReports,
                UserAbility::UpdateRescueStatus,
            ],
        };
    }

    public function hasAbility(UserAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    public function isBarangayScoped(): bool
    {
        return in_array($this, [self::BarangayCaptain, self::BarangayTanod], true);
    }

    public function requiresBarangay(): bool
    {
        return $this->isBarangayScoped();
    }
}

<?php

namespace App\Enums;

enum UserAbility: string
{
    case ValidateReport = 'validate-report';
    case OverrideValidation = 'override-validation';
    case ConsolidateReports = 'consolidate-reports';
    case DispatchAssets = 'dispatch-assets';
    case GenerateProvincialReport = 'generate-provincial-report';
    case SubmitImpactReport = 'submit-impact-report';
    case SubmitFloodReport = 'submit-flood-report';
    case ViewValidatedReports = 'view-validated-reports';
    case UpdateRescueStatus = 'update-rescue-status';
    case ManageUsers = 'manage-users';
    case ViewAllBarangays = 'view-all-barangays';
    case ViewOwnBarangay = 'view-own-barangay';

    public function label(): string
    {
        return match ($this) {
            self::ValidateReport => 'Validate reports',
            self::OverrideValidation => 'Override validation decisions',
            self::ConsolidateReports => 'Consolidate reports',
            self::DispatchAssets => 'Dispatch assets',
            self::GenerateProvincialReport => 'Generate provincial reports',
            self::SubmitImpactReport => 'Submit barangay impact reports',
            self::SubmitFloodReport => 'Submit flood reports',
            self::ViewValidatedReports => 'View validated reports',
            self::UpdateRescueStatus => 'Update rescue status',
            self::ManageUsers => 'Manage user accounts',
            self::ViewAllBarangays => 'View all barangays',
            self::ViewOwnBarangay => 'View own barangay',
        };
    }
}

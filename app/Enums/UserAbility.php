<?php

namespace App\Enums;

enum UserAbility: string
{
    case ValidateReport = 'validate-report';
    case ConsolidateReports = 'consolidate-reports';
    case DispatchAssets = 'dispatch-assets';
    case GenerateProvincialReport = 'generate-provincial-report';
    case MonitorDashboard = 'monitor-dashboard';
    case SubmitImpactReport = 'submit-impact-report';
    case SubmitFloodObservation = 'submit-flood-observation';
    case ViewValidatedReports = 'view-validated-reports';
    case UpdateRescueStatus = 'update-rescue-status';
    case ManageUsers = 'manage-users';
    case ViewAllBarangays = 'view-all-barangays';
    case ViewOwnBarangay = 'view-own-barangay';

    public function label(): string
    {
        return match ($this) {
            self::ValidateReport => 'Validate reports',
            self::ConsolidateReports => 'Consolidate reports',
            self::DispatchAssets => 'Dispatch assets',
            self::GenerateProvincialReport => 'Generate provincial reports',
            self::MonitorDashboard => 'Monitor dashboard',
            self::SubmitImpactReport => 'Submit barangay impact reports',
            self::SubmitFloodObservation => 'Submit flood-level observations',
            self::ViewValidatedReports => 'View validated reports',
            self::UpdateRescueStatus => 'Update rescue status',
            self::ManageUsers => 'Manage user accounts',
            self::ViewAllBarangays => 'View all barangays',
            self::ViewOwnBarangay => 'View own barangay',
        };
    }
}

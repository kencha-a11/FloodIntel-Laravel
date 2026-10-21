<?php

use App\Enums\AccessInterface;
use App\Enums\UserAbility;
use App\Enums\UserRole;

it('maps each role to its primary interface', function () {
    expect(UserRole::MdrrmoOfficer->interface())->toBe(AccessInterface::CommandCenter)
        ->and(UserRole::MdrrmoStaff->interface())->toBe(AccessInterface::CommandCenter)
        ->and(UserRole::BarangayCaptain->interface())->toBe(AccessInterface::FieldReporter)
        ->and(UserRole::BarangayTanod->interface())->toBe(AccessInterface::FieldReporter)
        ->and(UserRole::RescueLeader->interface())->toBe(AccessInterface::FieldReporter);
});

it('grants the expected abilities to each role', function (UserRole $role, array $abilities) {
    expect($role->abilities())->toEqualCanonicalizing($abilities);
})->with([
    'officer' => [UserRole::MdrrmoOfficer, [
        UserAbility::ValidateReport,
        UserAbility::GenerateProvincialReport,
        UserAbility::MonitorDashboard,
        UserAbility::ManageUsers,
        UserAbility::ViewAllBarangays,
        UserAbility::ViewValidatedReports,
    ]],
    'staff' => [UserRole::MdrrmoStaff, [
        UserAbility::ConsolidateReports,
        UserAbility::DispatchAssets,
        UserAbility::MonitorDashboard,
        UserAbility::ViewAllBarangays,
        UserAbility::ViewValidatedReports,
    ]],
    'captain' => [UserRole::BarangayCaptain, [
        UserAbility::SubmitImpactReport,
        UserAbility::SubmitFloodReport,
        UserAbility::ViewOwnBarangay,
    ]],
    'tanod' => [UserRole::BarangayTanod, [
        UserAbility::SubmitFloodObservation,
        UserAbility::SubmitFloodReport,
        UserAbility::ViewOwnBarangay,
    ]],
    'rescue' => [UserRole::RescueLeader, [
        UserAbility::ViewValidatedReports,
        UserAbility::UpdateRescueStatus,
    ]],
]);

it('flags the barangay scoped roles', function (UserRole $role, bool $expected) {
    expect($role->isBarangayScoped())->toBe($expected)
        ->and($role->requiresBarangay())->toBe($expected);
})->with([
    [UserRole::BarangayCaptain, true],
    [UserRole::BarangayTanod, true],
    [UserRole::MdrrmoOfficer, false],
    [UserRole::MdrrmoStaff, false],
    [UserRole::RescueLeader, false],
]);

it('provides a label for every ability', function () {
    foreach (UserAbility::cases() as $ability) {
        expect($ability->label())->toBeString()->not->toBeEmpty();
    }
});

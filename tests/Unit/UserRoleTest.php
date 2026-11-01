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
        UserAbility::OverrideValidation,
        UserAbility::GenerateProvincialReport,
        UserAbility::ManageUsers,
        UserAbility::ViewAllBarangays,
        UserAbility::ViewValidatedReports,
    ]],
    'staff' => [UserRole::MdrrmoStaff, [
        UserAbility::ValidateReport,
        UserAbility::ConsolidateReports,
        UserAbility::DispatchAssets,
        UserAbility::ViewAllBarangays,
        UserAbility::ViewValidatedReports,
    ]],
    'captain' => [UserRole::BarangayCaptain, [
        UserAbility::SubmitImpactReport,
        UserAbility::SubmitFloodReport,
        UserAbility::RecordAccessTag,
        UserAbility::ViewOwnBarangay,
    ]],
    'tanod' => [UserRole::BarangayTanod, [
        UserAbility::SubmitFloodReport,
        UserAbility::RecordAccessTag,
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

it('scopes every role that files a report or records a tag to a barangay', function (UserAbility $ability) {
    $holders = array_filter(
        UserRole::cases(),
        fn (UserRole $role): bool => $role->hasAbility($ability),
    );

    expect($holders)->not->toBeEmpty();

    foreach ($holders as $role) {
        expect($role->requiresBarangay())->toBeTrue();
    }
})->with([
    UserAbility::SubmitFloodReport,
    UserAbility::SubmitImpactReport,
    UserAbility::RecordAccessTag,
]);

it('keeps the rescue leader out of the barangay rule because it files nothing', function () {
    $rescue = UserRole::RescueLeader;

    expect($rescue->interface())->toBe(AccessInterface::FieldReporter)
        ->and($rescue->isBarangayScoped())->toBeFalse()
        ->and($rescue->hasAbility(UserAbility::SubmitFloodReport))->toBeFalse()
        ->and($rescue->hasAbility(UserAbility::SubmitImpactReport))->toBeFalse()
        ->and($rescue->hasAbility(UserAbility::ViewValidatedReports))->toBeTrue();
});

it('provides a label for every ability', function () {
    foreach (UserAbility::cases() as $ability) {
        expect($ability->label())->toBeString()->not->toBeEmpty();
    }
});

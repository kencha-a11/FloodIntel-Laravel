<?php

use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('allows command center roles to list barangays', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    Barangay::factory()->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/barangays')
        ->assertOk();
})->with([
    UserRole::MdrrmoOfficer,
    UserRole::MdrrmoStaff,
]);

it('forbids field roles from listing barangays', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/barangays')
        ->assertForbidden();
})->with([
    UserRole::BarangayCaptain,
    UserRole::BarangayTanod,
    UserRole::RescueLeader,
]);

it('forbids non-officer roles from managing users', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/users')
        ->assertForbidden();
})->with([
    UserRole::MdrrmoStaff,
    UserRole::BarangayCaptain,
    UserRole::BarangayTanod,
    UserRole::RescueLeader,
]);

it('requires authentication for role protected routes', function () {
    $this->getJson('/api/users')->assertUnauthorized();
    $this->getJson('/api/barangays')->assertUnauthorized();
});

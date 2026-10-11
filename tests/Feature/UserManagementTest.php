<?php

use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

it('lets the officer create a user and assign a barangay scoped role', function () {
    Notification::fake();

    $officer = User::factory()->officer()->create();
    $barangay = Barangay::factory()->create();

    $response = $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', [
            'first_name' => 'New',
            'last_name' => 'Captain',
            'email' => 'new-captain@example.com',
            'contact_number' => '+15551110001',
            'password' => 'password',
            'role' => UserRole::BarangayCaptain->value,
            'barangay_id' => $barangay->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.role', UserRole::BarangayCaptain->value)
        ->assertJsonPath('data.interface', 'field_reporter')
        ->assertJsonPath('data.barangay.id', $barangay->id);

    $user = User::where('email', 'new-captain@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::BarangayCaptain)
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('creates a command center user without requiring a barangay', function () {
    Notification::fake();

    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', [
            'first_name' => 'New',
            'last_name' => 'Staff',
            'email' => 'new-staff@example.com',
            'contact_number' => '+15551110002',
            'password' => 'password',
            'role' => UserRole::MdrrmoStaff->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', UserRole::MdrrmoStaff->value)
        ->assertJsonPath('data.barangay', null);
});

it('requires a barangay for a barangay scoped role', function () {
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', [
            'first_name' => 'New',
            'last_name' => 'Tanod',
            'email' => 'new-tanod@example.com',
            'contact_number' => '+15551110003',
            'password' => 'password',
            'role' => UserRole::BarangayTanod->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['barangay_id']);
});

it('rejects an unknown role when creating a user', function () {
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->postJson('/api/users', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'new-user@example.com',
            'contact_number' => '+15551110004',
            'password' => 'password',
            'role' => 'super_admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('lets the officer update a user role and barangay', function () {
    $officer = User::factory()->officer()->create();
    $target = User::factory()->tanod()->create();
    $barangay = Barangay::factory()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->patchJson("/api/users/{$target->id}", [
            'role' => UserRole::BarangayCaptain->value,
            'barangay_id' => $barangay->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.role', UserRole::BarangayCaptain->value);

    expect($target->fresh()->role)->toBe(UserRole::BarangayCaptain)
        ->and($target->fresh()->barangay_id)->toBe($barangay->id);
});

it('prevents the officer from changing their own role', function () {
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->patchJson("/api/users/{$officer->id}", [
            'role' => UserRole::BarangayTanod->value,
        ])
        ->assertForbidden();

    expect($officer->fresh()->role)->toBe(UserRole::MdrrmoOfficer);
});

it('prevents the officer from deleting their own account', function () {
    $officer = User::factory()->officer()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->deleteJson("/api/users/{$officer->id}")
        ->assertForbidden();

    expect(User::find($officer->id))->not->toBeNull();
});

it('lets the officer delete another user', function () {
    $officer = User::factory()->officer()->create();
    $target = User::factory()->staff()->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->deleteJson("/api/users/{$target->id}")
        ->assertNoContent();

    expect(User::find($target->id))->toBeNull();
});

it('lists users with their role and abilities', function () {
    $officer = User::factory()->officer()->create();
    User::factory()->count(3)->create();

    $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/users')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'role', 'role_label', 'interface', 'abilities']]]);
});

it('does not allow the role to be mass assigned', function () {
    $user = User::create([
        'first_name' => 'Sneaky',
        'last_name' => 'User',
        'email' => 'sneaky@example.com',
        'contact_number' => '+15559990000',
        'password' => 'password',
        'role' => UserRole::MdrrmoOfficer->value,
    ]);

    expect($user->fresh()->role)->toBe(UserRole::BarangayTanod);
});

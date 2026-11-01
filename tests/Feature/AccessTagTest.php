<?php

use App\Enums\AccessTagType;
use App\Enums\UserRole;
use App\Models\AccessTag;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('stores a tag as a place record with its capture instant', function () {
    $observing = now()->subMinutes(15);

    $tag = AccessTag::factory()->create([
        'type' => AccessTagType::NarrowAlley,
        'observed_at' => $observing,
        'latitude' => 14.9500000,
        'longitude' => 120.8300000,
        'gps_accuracy' => 8.5,
    ]);

    $stored = AccessTag::findOrFail($tag->id);

    expect($stored->type)->toBe(AccessTagType::NarrowAlley)
        ->and($stored->observed_at->toDateTimeString())->toBe($observing->toDateTimeString())
        ->and($stored->is_active)->toBeTrue()
        ->and($stored->barangay)->toBeInstanceOf(Barangay::class)
        ->and($stored->createdBy)->toBeInstanceOf(User::class);
});

it('keeps the recorded account and barangay as the tag owner', function () {
    $barangay = Barangay::factory()->create();
    $tanod = User::factory()->tanod()->forBarangay($barangay)->create();

    $tag = AccessTag::factory()->create([
        'barangay_id' => $barangay->id,
        'created_by' => $tanod->id,
    ]);

    expect($tag->barangay_id)->toBe($barangay->id)
        ->and($tag->created_by)->toBe($tanod->id);
});

it('scopes reads to every barangay for the command center', function (UserRole $role) {
    $user = User::factory()->role($role)->create();
    AccessTag::factory()->count(2)->create();

    expect(AccessTag::visibleTo($user)->count())->toBe(2);
})->with([
    'officer' => UserRole::MdrrmoOfficer,
    'staff' => UserRole::MdrrmoStaff,
]);

it('scopes reads to its own barangay for a field account', function (UserRole $role) {
    $barangay = Barangay::factory()->create();
    $user = User::factory()->role($role)->forBarangay($barangay)->create();
    AccessTag::factory()->create(['barangay_id' => $barangay->id]);
    AccessTag::factory()->create();

    $visible = AccessTag::visibleTo($user)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->barangay_id)->toBe($barangay->id);
})->with([
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
]);

it('scopes reads to its own records for an account with no wider right', function () {
    $rescue = User::factory()->rescueLeader()->create();
    AccessTag::factory()->create(['created_by' => $rescue->id]);
    AccessTag::factory()->create();

    $visible = AccessTag::visibleTo($rescue)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->created_by)->toBe($rescue->id);
});

it('records a constraint the closed set does not name only with a note', function () {
    $tag = AccessTag::factory()->other()->create();

    expect($tag->type)->toBe(AccessTagType::Other)
        ->and($tag->type->requiresNote())->toBeTrue()
        ->and($tag->note)->not->toBeEmpty();
});

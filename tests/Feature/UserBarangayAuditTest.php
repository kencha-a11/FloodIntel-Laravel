<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(LazilyRefreshDatabase::class);

it('passes when every barangay scoped account carries a barangay', function () {
    User::factory()->officer()->create();
    User::factory()->captain()->create();
    User::factory()->tanod()->create();
    User::factory()->rescueLeader()->create();

    $exitCode = Artisan::call('users:audit-barangay');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No account is missing its barangay (2 checked).')
        ->and($output)->toContain(UserRole::BarangayCaptain->label())
        ->and($output)->toContain(UserRole::BarangayTanod->label());
});

it('fails and names an account whose barangay was cleared outside the API', function () {
    $captain = User::factory()->captain()->create();

    $captain->forceFill(['barangay_id' => null])->save();

    $exitCode = Artisan::call('users:audit-barangay');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('1 account(s) require a barangay but have none')
        ->and($output)->toContain($captain->email)
        ->and($output)->toContain((string) $captain->id);
});

it('reports the counts and the violating account as JSON', function () {
    User::factory()->tanod()->create();
    $orphan = User::factory()->captain()->create();
    $orphan->forceFill(['barangay_id' => null])->save();

    Artisan::call('users:audit-barangay', ['--json' => true]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['accounts'])->toBe(2)
        ->and($report['missing_barangay'])->toBe(1)
        ->and($report['roles'][UserRole::BarangayCaptain->value])->toMatchArray([
            'accounts' => 1,
            'missing_barangay' => 1,
        ])
        ->and($report['roles'][UserRole::BarangayTanod->value])->toMatchArray([
            'accounts' => 1,
            'missing_barangay' => 0,
        ])
        ->and($report['violations'])->toBe([[
            'id' => $orphan->id,
            'email' => $orphan->email,
            'role' => UserRole::BarangayCaptain->value,
        ]]);
});

it('does not treat a role without a barangay requirement as a violation', function () {
    User::factory()->officer()->create();
    User::factory()->staff()->create();
    User::factory()->rescueLeader()->create();

    $exitCode = Artisan::call('users:audit-barangay', ['--json' => true]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['accounts'])->toBe(0)
        ->and($report['missing_barangay'])->toBe(0)
        ->and($report['violations'])->toBe([])
        ->and(User::query()->whereNull('barangay_id')->count())->toBe(3);
});

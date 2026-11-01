<?php

use App\Enums\AccessInterface;
use App\Enums\UserAbility;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('publishes every role with the interface and abilities the enum grants', function () {
    $officer = User::factory()->officer()->create();

    $response = $this->withToken($officer->createToken('t')->plainTextToken)
        ->getJson('/api/roles')
        ->assertOk();

    $rows = collect($response->json('data'))->keyBy('value');

    expect($rows)->toHaveCount(count(UserRole::cases()));

    foreach (UserRole::cases() as $role) {
        $row = $rows[$role->value];

        expect($row['label'])->toBe($role->label())
            ->and($row['interface'])->toBe([
                'value' => $role->interface()->value,
                'label' => $role->interface()->label(),
            ])
            ->and($row['barangay_scoped'])->toBe($role->isBarangayScoped())
            ->and(array_column($row['abilities'], 'value'))
            ->toBe(array_map(
                fn (UserAbility $ability): string => $ability->value,
                $role->abilities(),
            ))
            ->and(array_column($row['abilities'], 'label'))
            ->toBe(array_map(
                fn (UserAbility $ability): string => $ability->label(),
                $role->abilities(),
            ));
    }
});

it('publishes only abilities declared in the closed catalogue', function () {
    $officer = User::factory()->officer()->create();

    $published = collect(
        $this->withToken($officer->createToken('t')->plainTextToken)
            ->getJson('/api/roles')
            ->assertOk()
            ->json('data'),
    )
        ->flatMap(fn (array $row): array => array_column($row['abilities'], 'value'))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $declared = array_map(fn (UserAbility $ability): string => $ability->value, UserAbility::cases());
    sort($declared);

    expect($published)->toBe($declared);
});

it('names the interfaces the enum declares', function () {
    $officer = User::factory()->officer()->create();

    $rows = collect(
        $this->withToken($officer->createToken('t')->plainTextToken)
            ->getJson('/api/roles')
            ->assertOk()
            ->json('data'),
    );

    $published = $rows->map(fn (array $row): array => $row['interface'])->unique('value')->values();

    expect($published->count())->toBe(count(AccessInterface::cases()));

    foreach (AccessInterface::cases() as $interface) {
        expect($published->firstWhere('value', $interface->value)['label'])
            ->toBe($interface->label());
    }
});

it('refuses the matrix to every account without manage-users', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->withToken($user->createToken('t')->plainTextToken)
        ->getJson('/api/roles')
        ->assertForbidden();
})->with([
    'staff' => UserRole::MdrrmoStaff,
    'captain' => UserRole::BarangayCaptain,
    'tanod' => UserRole::BarangayTanod,
    'rescue leader' => UserRole::RescueLeader,
]);

it('offers no way to write the matrix', function () {
    $officer = User::factory()->officer()->create();
    $token = $officer->createToken('t')->plainTextToken;

    $this->withToken($token)->postJson('/api/roles')->assertMethodNotAllowed();
    $this->withToken($token)->patchJson('/api/roles')->assertMethodNotAllowed();
    $this->withToken($token)->deleteJson('/api/roles')->assertMethodNotAllowed();
});

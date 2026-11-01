<?php

use App\Enums\UserAbility;
use App\Enums\UserRole;

/**
 * The reservation map D-57 records: each ability has no server consumer yet
 * because the task named here will add one. Adding to this map is a decision,
 * not a shortcut, and the assertions below fail when a consumer appears.
 *
 * @return array<string, string>
 */
function reservedAbilityOwners(): array
{
    return [
        UserAbility::RecordAccessTag->value => 'A11Y-03',
        UserAbility::GenerateProvincialReport->value => 'SITREP-03',
        UserAbility::DispatchAssets->value => 'DISPATCH-02',
        UserAbility::UpdateRescueStatus->value => 'DISPATCH-03',
    ];
}

/**
 * Application files outside the enum that read the ability by name.
 *
 * This suite does not boot the application, so it walks `app/` with SPL
 * rather than the framework's filesystem facade.
 *
 * @return list<string>
 */
function abilityConsumers(UserAbility $ability): array
{
    $needle = 'UserAbility::'.$ability->name;
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    $enums = $root.DIRECTORY_SEPARATOR.'Enums'.DIRECTORY_SEPARATOR;
    $consumers = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile()
            || $file->getExtension() !== 'php'
            || str_starts_with($path, $enums)) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), $needle)) {
            $consumers[] = substr($path, strlen($root) + 1);
        }
    }

    sort($consumers);

    return $consumers;
}

it('consumes every ability that is not reserved', function () {
    $reserved = reservedAbilityOwners();

    foreach (UserAbility::cases() as $ability) {
        if (array_key_exists($ability->value, $reserved)) {
            continue;
        }

        expect(abilityConsumers($ability))->not->toBeEmpty(
            "{$ability->value} has no server consumer and no reserved owner",
        );
    }
});

it('reserves exactly the abilities whose owners are in scope', function () {
    expect(array_keys(reservedAbilityOwners()))->toBe([
        UserAbility::RecordAccessTag->value,
        UserAbility::GenerateProvincialReport->value,
        UserAbility::DispatchAssets->value,
        UserAbility::UpdateRescueStatus->value,
    ]);

    foreach (reservedAbilityOwners() as $value => $owner) {
        $ability = UserAbility::from($value);

        expect($owner)->toMatch('/^[A-Z][A-Z0-9]*-\d{2}$/')
            ->and(abilityConsumers($ability))->toBe(
                [],
                "{$value} gained a consumer; reclassify it in the map and remove the reservation",
            )
            ->and(collect(UserRole::cases())->contains(
                fn (UserRole $role): bool => $role->hasAbility($ability),
            ))->toBeTrue("{$value} is reserved but no role holds it");
    }
});

it('grants every ability to at least one role', function () {
    foreach (UserAbility::cases() as $ability) {
        expect(collect(UserRole::cases())->contains(
            fn (UserRole $role): bool => $role->hasAbility($ability),
        ))->toBeTrue("{$ability->value} is held by no role");
    }
});

<?php

use App\Enums\AccessTagType;

it('declares the closed taxonomy', function () {
    expect(array_map(fn (AccessTagType $type): string => $type->value, AccessTagType::cases()))
        ->toBe([
            'narrow_alley',
            'no_vehicle_access',
            'stairs_or_steep',
            'structurally_blocked',
            'other',
        ]);
});

it('labels every type once', function () {
    $labels = [];

    foreach (AccessTagType::cases() as $type) {
        expect($type->label())->toBeString()->not->toBeEmpty();

        $labels[] = $type->label();
    }

    expect(array_unique($labels))->toHaveCount(count(AccessTagType::cases()));
});

it('requires a note only for the other type', function () {
    foreach (AccessTagType::cases() as $type) {
        expect($type->requiresNote())->toBe($type === AccessTagType::Other);
    }
});

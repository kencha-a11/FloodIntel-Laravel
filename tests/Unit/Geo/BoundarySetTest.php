<?php

use App\Geo\BoundarySet;

function boundarySquare(float $minX, float $minY, float $maxX, float $maxY): array
{
    return [[
        [$minX, $minY],
        [$maxX, $minY],
        [$maxX, $maxY],
        [$minX, $maxY],
        [$minX, $minY],
    ]];
}

it('finds a point inside a polygon', function () {
    $boundaries = new BoundarySet(['PH0305402001' => boundarySquare(0, 0, 1, 1)]);

    expect($boundaries->locate(0.5, 0.5))->toBe('PH0305402001')
        ->and($boundaries->locate(1.5, 0.5))->toBeNull();
});

it('treats a point on an edge as inside', function () {
    $boundaries = new BoundarySet(['PH0305402001' => boundarySquare(0, 0, 1, 1)]);

    expect($boundaries->locate(1, 0.5))->toBe('PH0305402001')
        ->and($boundaries->locate(0, 1))->toBe('PH0305402001');
});

it('resolves a shared edge to the lowest barangay code', function () {
    $boundaries = new BoundarySet([
        'PH0305402003' => boundarySquare(0, 0, 1, 1),
        'PH0305402001' => boundarySquare(1, 0, 2, 1),
    ]);

    expect($boundaries->locate(1, 0.5))->toBe('PH0305402001');
});

it('does not treat a hole as inside the polygon', function () {
    $ring = [[
        [0, 0], [10, 0], [10, 10], [0, 10], [0, 0],
    ], [
        [4, 4], [6, 4], [6, 6], [4, 6], [4, 4],
    ]];

    $boundaries = new BoundarySet(['PH0305402001' => $ring]);

    expect($boundaries->locate(1, 1))->toBe('PH0305402001')
        ->and($boundaries->locate(5, 5))->toBeNull();
});

it('computes the extent from every polygon', function () {
    $boundaries = new BoundarySet([
        'PH0305402001' => boundarySquare(0, 0, 1, 2),
        'PH0305402003' => boundarySquare(1, 0, 3, 1),
    ]);

    expect($boundaries->extent())->toBe([0.0, 0.0, 3.0, 2.0])
        ->and($boundaries->coversExtent(3, 2))->toBeTrue()
        ->and($boundaries->coversExtent(3.1, 2))->toBeFalse();
});

<?php

use App\Realtime\LatencyStatistics;

it('reports the median and maximum of an odd sample', function () {
    $summary = LatencyStatistics::summarize([1200, 800, 4000], 24);

    expect($summary['samples'])->toBe(3)
        ->and($summary['excluded'])->toBe(0)
        ->and($summary['median_ms'])->toBe(1200)
        ->and($summary['max_ms'])->toBe(4000)
        ->and($summary['bound_ms'])->toBe(3000)
        ->and($summary['breaches'])->toBe(1)
        ->and($summary['window_hours'])->toBe(24);
});

it('averages the two middle samples of an even count', function () {
    $summary = LatencyStatistics::summarize([1000, 2000, 3000, 4000], 24);

    expect($summary['median_ms'])->toBe(2500)
        ->and($summary['max_ms'])->toBe(4000);
});

it('counts null samples as excluded rather than as zero latency', function () {
    $summary = LatencyStatistics::summarize([null, 900, null, 1200], 6);

    expect($summary['samples'])->toBe(2)
        ->and($summary['excluded'])->toBe(2)
        ->and($summary['median_ms'])->toBe(1050)
        ->and($summary['max_ms'])->toBe(1200);
});

it('reports an empty window without inventing a median', function () {
    $summary = LatencyStatistics::summarize([], 24);

    expect($summary['samples'])->toBe(0)
        ->and($summary['excluded'])->toBe(0)
        ->and($summary['median_ms'])->toBeNull()
        ->and($summary['max_ms'])->toBeNull()
        ->and($summary['breaches'])->toBe(0);
});

it('does not count a sample exactly on the bound as a breach', function () {
    $summary = LatencyStatistics::summarize([3000, 3001], 1);

    expect($summary['breaches'])->toBe(1);
});

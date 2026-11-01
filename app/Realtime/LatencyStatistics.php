<?php

namespace App\Realtime;

/**
 * Median and maximum propagation latency for a stated window (FR-70, D-67).
 *
 * A null latency is a record excluded for a missing or unusable timestamp; it
 * is reported beside the sample count so a selective window cannot be
 * presented as a capability.
 */
final class LatencyStatistics
{
    public const BOUND_MS = 3000;

    /**
     * @param  list<int|null>  $latencies
     * @return array{window_hours: int, samples: int, excluded: int, median_ms: int|null, max_ms: int|null, bound_ms: int, breaches: int}
     */
    public static function summarize(array $latencies, int $windowHours): array
    {
        $included = array_values(array_filter(
            $latencies,
            static fn (mixed $value): bool => is_int($value),
        ));

        sort($included);

        $count = count($included);
        $median = null;

        if ($count > 0) {
            $middle = intdiv($count, 2);
            $median = $count % 2 === 1
                ? $included[$middle]
                : (int) round(($included[$middle - 1] + $included[$middle]) / 2);
        }

        return [
            'window_hours' => $windowHours,
            'samples' => $count,
            'excluded' => count($latencies) - $count,
            'median_ms' => $median,
            'max_ms' => $count > 0 ? $included[$count - 1] : null,
            'bound_ms' => self::BOUND_MS,
            'breaches' => count(array_filter(
                $included,
                static fn (int $value): bool => $value > self::BOUND_MS,
            )),
        ];
    }
}

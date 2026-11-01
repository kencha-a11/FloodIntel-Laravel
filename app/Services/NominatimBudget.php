<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class NominatimBudget
{
    private const NEXT_SLOT_KEY = 'geocoder:nominatim:next-slot';

    private const LOCK_KEY = 'geocoder:nominatim:budget';

    /**
     * Reserve the next provider slot, or report that the budget is spent.
     *
     * The slot is application-wide state in the cache store, so every worker
     * shares the same request-per-interval budget instead of one per caller.
     */
    public function reserve(): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            $now = $this->now();
            $nextSlot = (float) Cache::get(self::NEXT_SLOT_KEY, 0.0);

            if ($now < $nextSlot) {
                return false;
            }

            $interval = max(0, (int) config('services.geocoder.min_interval_ms')) / 1000;

            Cache::put(self::NEXT_SLOT_KEY, $now + $interval, now()->addMinutes(10));

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Seconds until a caller may reserve a slot.
     */
    public function waitSeconds(): float
    {
        return max(0.0, (float) Cache::get(self::NEXT_SLOT_KEY, 0.0) - $this->now());
    }

    private function now(): float
    {
        return now()->getPreciseTimestamp(6) / 1_000_000;
    }
}

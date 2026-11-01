<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The cutoff-to-cutoff consolidation window of D-24: 4:00 AM and 3:00 PM in
 * Asia/Manila, with the bounds derived from a server instant rather than a
 * device clock.
 */
final class ConsolidationWindow
{
    /**
     * @param  array<int, string>  $cutoffs
     */
    private function __construct(
        public readonly CarbonImmutable $now,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $nextCutoffAt,
        public readonly array $cutoffs,
        public readonly string $timezone,
    ) {}

    public static function current(?CarbonInterface $now = null): self
    {
        $timezone = (string) config('deadlines.timezone', 'Asia/Manila');
        $cutoffs = array_values(array_map('strval', (array) config('deadlines.cutoffs', ['04:00', '15:00'])));

        $instant = $now === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($now)->setTimezone($timezone);

        $instants = [];
        $today = $instant->startOfDay();

        foreach ([$today->subDay(), $today, $today->addDay()] as $day) {
            foreach ($cutoffs as $cutoff) {
                [$hour, $minute] = array_map('intval', explode(':', $cutoff));
                $instants[] = $day->setTime($hour, $minute);
            }
        }

        usort($instants, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        $startsAt = null;
        $nextCutoffAt = null;

        foreach ($instants as $candidate) {
            if ($candidate->lessThanOrEqualTo($instant)) {
                $startsAt = $candidate;

                continue;
            }

            $nextCutoffAt = $candidate;

            break;
        }

        return new self(
            $instant,
            $startsAt ?? $instants[0],
            $nextCutoffAt ?? $instants[array_key_last($instants)],
            $cutoffs,
            $timezone,
        );
    }

    public function label(): string
    {
        return $this->startsAt->format('g:i A').' – '.$this->nextCutoffAt->format('g:i A');
    }
}

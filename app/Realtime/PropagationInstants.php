<?php

namespace App\Realtime;

use App\Models\FloodReport;
use Carbon\CarbonInterface;

/**
 * The server receipt instant each event is measured from (FR-70, D-67).
 *
 * A submission's receipt instant is the row's server-set created_at; a status
 * change is measured from the transition's own timestamp. Reject is the only
 * transition with one until VALID-04/05 land, so any other transition is
 * recorded as excluded rather than guessed from updated_at.
 */
final class PropagationInstants
{
    /**
     * A sample older than this is treated as unusable rather than a
     * propagation measurement (a client that applied an update long after it
     * was published is not measuring the transport).
     */
    public const MAX_MEASURABLE_MS = 300_000;

    public static function receivedAt(FloodReport $report, string $event): ?CarbonInterface
    {
        return match ($event) {
            ReportMessage::CREATED => $report->created_at,
            ReportMessage::STATUS_CHANGED => $report->rejected_at,
            default => null,
        };
    }

    public static function latencyMs(
        FloodReport $report,
        string $event,
        CarbonInterface $appliedAt,
    ): ?int {
        $receivedAt = self::receivedAt($report, $event);

        if ($receivedAt === null) {
            return null;
        }

        $latency = $appliedAt->getTimestampMs() - $receivedAt->getTimestampMs();

        if ($latency < 0 || $latency > self::MAX_MEASURABLE_MS) {
            return null;
        }

        return $latency;
    }
}

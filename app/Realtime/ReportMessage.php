<?php

namespace App\Realtime;

use App\Models\FloodReport;

/**
 * The two declared realtime messages (FR-67, D-64).
 *
 * The payload carries only the report id, its barangay id, the status, and the
 * changed field names. Coordinates, address, photo, notes, and reporter
 * identity are never included: a subscriber refetches through the authorized
 * API, so the channel cannot bypass a policy.
 */
final class ReportMessage
{
    public const CREATED = 'report.created';

    public const STATUS_CHANGED = 'report.status_changed';

    /**
     * Field names a status-change event may announce. A field outside this
     * list is dropped rather than broadcast.
     */
    private const CHANGEABLE_FIELDS = ['status'];

    /**
     * @param  list<string>  $topics
     * @param  array{id: int, barangay_id: int, status: string, changed: list<string>}  $payload
     */
    private function __construct(
        public readonly array $topics,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public static function created(FloodReport $report): self
    {
        return new self(
            ChannelScope::forReport($report),
            self::CREATED,
            [
                'id' => (int) $report->getKey(),
                'barangay_id' => (int) $report->barangay_id,
                'status' => $report->status->value,
                'changed' => [],
            ],
        );
    }

    /**
     * @param  list<string>  $changed
     */
    public static function statusChanged(FloodReport $report, array $changed): self
    {
        return new self(
            ChannelScope::forReport($report),
            self::STATUS_CHANGED,
            [
                'id' => (int) $report->getKey(),
                'barangay_id' => (int) $report->barangay_id,
                'status' => $report->status->value,
                'changed' => array_values(array_intersect($changed, self::CHANGEABLE_FIELDS)),
            ],
        );
    }
}

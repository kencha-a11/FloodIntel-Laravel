<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The immutable result of one stage-1 aggregation (D-40, D-41).
 *
 * Everything a document will publish lives here, including the provenance
 * rows, so `SituationalReportAggregator::prepare()` can write the snapshot and
 * the template never needs a live query.
 */
final class SituationalReportSnapshot
{
    /**
     * The format version recorded until the provincial transcript (D-39)
     * defines the document's versions; stage 2 bumps it when impact figures
     * become available (D-41).
     */
    public const FORMAT_VERSION = '1';

    public const STAGE_TWO_REASON = 'Impact report data is not collected yet (module 03); affected families and individuals cannot be published.';

    /**
     * @param  array{basis: string, validated: int, pending: int, returned: int, rejected: int}  $statusCounts
     * @param  array{reports: int, included_reports: int, alert_level: string|null, flood_level: string|null}  $totals
     * @param  array{samples: int, excluded_null_observation: int, mean_seconds: float|null, median_seconds: float|null, max_seconds: int|null}  $latency
     * @param  list<array{barangay_id: int, barangay: string, barangay_code: string, no_report_received: bool, reports: int, included_reports: int, status_counts: array{validated: int, pending: int, returned: int, rejected: int}, alert_level: string|null, flood_level: string|null, affected_families: int|null, affected_individuals: int|null}>  $barangays
     * @param  list<array{flood_report_id: int, barangay_id: int, alert_level: string|null, flood_level: string|null, observed_at: CarbonImmutable, validated_at: CarbonImmutable}>  $includedReports
     * @param  array{available: bool, reason: string, affected_families: int|null, affected_individuals: int|null}  $stageTwo
     */
    public function __construct(
        public readonly CarbonImmutable $windowStartsAt,
        public readonly CarbonImmutable $windowEndsAt,
        public readonly CarbonImmutable $snapshotAt,
        public readonly string $cutoffLabel,
        public readonly string $timezone,
        public readonly array $statusCounts,
        public readonly array $totals,
        public readonly array $latency,
        public readonly array $barangays,
        public readonly array $includedReports,
        public readonly array $stageTwo,
    ) {}
}

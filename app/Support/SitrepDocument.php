<?php

namespace App\Support;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\SitrepSection;
use App\Models\SituationalReport;
use Carbon\CarbonInterface;

/**
 * The single view-model behind preview, print, and DOCX (D-43, D-147).
 *
 * Built only from a stored snapshot's frozen columns and relations — never
 * a live aggregate — so the same revision renders identically every time.
 * Format-version-1 rows have null content: their stage-1 columns render and
 * the supplement sections are marked unavailable.
 */
final class SitrepDocument
{
    public const ADAPTATION_NOTE = 'Aggregate-only adaptation of the PDRRMO SITREP template (D-45, amended 2026-11-18): barangay-level figures only; no individual identities, addresses, coordinates, or photographs.';

    /**
     * @return array<string, mixed>
     */
    public static function build(SituationalReport $report): array
    {
        $report->loadMissing(['inclusions', 'issuance']);
        $content = $report->content;
        $issuance = $report->issuance;

        $sections = [];

        foreach (SitrepSection::cases() as $section) {
            $sections[$section->value] = [];
        }

        foreach ($content['supplements'] ?? [] as $row) {
            $sections[$row['section']][] = $row;
        }

        return [
            'meta' => [
                'id' => $report->id,
                'format_version' => $report->format_version,
                'revision' => $report->revision,
                'cutoff_label' => $report->cutoff_label,
                'timezone' => $report->timezone,
                'window_starts_at' => self::label($report->window_starts_at, $report->timezone),
                'window_ends_at' => self::label($report->window_ends_at, $report->timezone),
                'snapshot_at' => self::label($report->snapshot_at, $report->timezone),
                'prepared_by_name' => $report->generated_by_name,
                'prepared_by_role' => $report->generated_by_role,
                'has_content' => $content !== null,
            ],
            'event' => $content['event'] ?? null,
            'office' => $issuance?->office ?? config('sitrep.office'),
            'issuance' => $issuance === null ? null : [
                'report_number' => $issuance->report_number,
                'issued_by_name' => $issuance->issued_by_name,
                'issued_by_role' => $issuance->issued_by_role,
                'issued_at' => self::label($issuance->issued_at, $report->timezone),
            ],
            'adaptation' => $content['adaptation'] ?? self::ADAPTATION_NOTE,
            'totals' => $report->totals,
            'status_counts' => $report->status_counts,
            'latency' => $report->latency,
            'stage_two' => $report->stage_two,
            'barangays' => array_map(fn (array $row): array => [
                ...$row,
                'alert_label' => self::alertLabel($row['alert_level']),
                'flood_label' => self::floodLabel($row['flood_level']),
            ], $report->barangays),
            'sections' => $sections,
            'cost_taxonomy' => self::costTaxonomy($sections['damage_costs']),
            'provenance' => $report->inclusions->map(fn ($inclusion): array => [
                'flood_report_id' => $inclusion->flood_report_id,
                'barangay_id' => $inclusion->barangay_id,
                'alert_label' => self::alertLabel($inclusion->alert_level?->value),
                'flood_label' => self::floodLabel($inclusion->flood_level?->value),
                'observed_at' => self::label($inclusion->observed_at, $report->timezone),
                'validated_at' => self::label($inclusion->validated_at, $report->timezone),
            ])->all(),
        ];
    }

    public static function alertLabel(?string $value): string
    {
        return $value === null ? 'Unavailable' : (AlertLevel::tryFrom($value)?->label() ?? 'Unavailable');
    }

    public static function floodLabel(?string $value): string
    {
        return $value === null ? 'Unavailable' : (FloodLevel::tryFrom($value)?->label() ?? 'Unavailable');
    }

    private static function label(?CarbonInterface $instant, string $timezone): ?string
    {
        return $instant?->copy()->setTimezone($timezone)->format('F j, Y g:i A');
    }

    /**
     * The template's fixed cost-of-damages taxonomy (format fidelity pass).
     * Presentation only: each leaf row holds at most one frozen supplement
     * (first match wins, never double-counted); supplements that match no
     * fixed row append as their own rows. Amounts are the frozen figures,
     * never re-derived. A row with no supplement renders "Unavailable".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, group: bool, indent: bool, cost: ?string, remarks: ?string}>
     */
    private static function costTaxonomy(array $rows): array
    {
        $taxonomy = [
            ['label' => 'Infrastructure (Roads, Bridges, and Other Structures)', 'keys' => ['infrastructure', 'roads', 'bridges']],
            ['label' => 'School Facilities', 'keys' => ['school']],
            ['label' => 'Health Facilities', 'keys' => ['health']],
            ['label' => 'Agriculture', 'keys' => [], 'group' => true],
            ['label' => 'Crops', 'keys' => ['crops'], 'indent' => true],
            ['label' => 'Livestock', 'keys' => ['livestock'], 'indent' => true],
            ['label' => 'HVCC', 'keys' => ['hvcc'], 'indent' => true],
            ['label' => 'Fisheries', 'keys' => ['fisheries'], 'indent' => true],
            ['label' => 'Other Agricultural Facilities / Equipment', 'keys' => ['otheragricultural', 'agriculturalfacilities', 'agriculture'], 'indent' => true],
            ['label' => 'Other Properties (Private Properties)', 'keys' => ['otherproperties', 'privateproperties', 'private']],
        ];

        $used = [];
        $out = [];

        foreach ($taxonomy as $entry) {
            $isGroup = $entry['group'] ?? false;
            $cost = null;
            $remarks = null;

            if (! $isGroup) {
                foreach ($rows as $i => $row) {
                    if (isset($used[$i])) {
                        continue;
                    }

                    $category = self::normalizeKey((string) ($row['payload']['category'] ?? ''));

                    foreach ($entry['keys'] as $key) {
                        if ($category !== '' && str_contains($category, $key)) {
                            $cost = self::costString($row);
                            $remarks = isset($row['payload']['estimate_status']) ? (string) $row['payload']['estimate_status'] : null;
                            $used[$i] = true;

                            break 2;
                        }
                    }
                }
            }

            $out[] = [
                'label' => $entry['label'],
                'group' => $isGroup,
                'indent' => $entry['indent'] ?? false,
                'cost' => $cost,
                'remarks' => $remarks,
            ];
        }

        foreach ($rows as $i => $row) {
            if (isset($used[$i])) {
                continue;
            }

            $out[] = [
                'label' => (string) ($row['payload']['category'] ?? 'Other'),
                'group' => false,
                'indent' => false,
                'cost' => self::costString($row),
                'remarks' => isset($row['payload']['estimate_status']) ? (string) $row['payload']['estimate_status'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function costString(array $row): ?string
    {
        $amount = $row['payload']['amount'] ?? null;

        if ($amount === null || $amount === '') {
            return null;
        }

        return ($row['payload']['currency'] ?? 'PHP').' '.$amount;
    }

    private static function normalizeKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }
}

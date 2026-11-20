<?php

namespace App\Services;

use App\Support\SitrepDocument;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * DOCX export of the adapted SITREP (D-147, G5: PDF + DOCX).
 *
 * Renders the same frozen view-model as the Blade preview — never a live
 * aggregate — on long-bond paper (8.5 × 13 in) with 0.5 in margins. The
 * browser print path remains the PDF file of record (D-43); this renderer
 * produces the editable copy from identical content.
 */
final class SitrepDocxRenderer
{
    private const PAGE_WIDTH_TWIPS = 12240; // 8.5 in

    private const PAGE_HEIGHT_TWIPS = 18720; // 13 in

    private const MARGIN_TWIPS = 720; // 0.5 in

    /**
     * @param  array<string, mixed>  $doc  the SitrepDocument view-model
     */
    public function render(array $doc): PhpWord
    {
        // The CLI PHP may lack ext-zip; PclZip ships with PhpWord and
        // produces the same OOXML package.
        Settings::setZipClass(class_exists(\ZipArchive::class) ? Settings::ZIPARCHIVE : Settings::PCLZIP);

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);

        $section = $word->addSection([
            'pageSizeW' => self::PAGE_WIDTH_TWIPS,
            'pageSizeH' => self::PAGE_HEIGHT_TWIPS,
            'marginTop' => self::MARGIN_TWIPS,
            'marginBottom' => self::MARGIN_TWIPS,
            'marginLeft' => self::MARGIN_TWIPS,
            'marginRight' => self::MARGIN_TWIPS,
        ]);

        $this->letterhead($section, $doc);
        $this->overview($section, $doc);
        $this->effects($section, $doc);
        $this->actions($section, $doc);
        $this->signatory($section, $doc);
        $this->annex($section, $doc);

        return $word;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function letterhead(Section $section, array $doc): void
    {
        foreach (['country', 'province', 'municipality', 'unit', 'address', 'email'] as $line) {
            $section->addText(
                $doc['office'][$line],
                ['bold' => in_array($line, ['municipality', 'unit'], true), 'size' => $line === 'municipality' ? 12 : 10],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
            );
        }

        $section->addText(
            'SITUATIONAL REPORT No. '.($doc['issuance']['report_number'] ?? '— (unissued draft)'),
            ['bold' => true, 'size' => 13],
            ['alignment' => Jc::CENTER, 'spaceBefore' => 200, 'spaceAfter' => 60],
        );
        $section->addText(
            $doc['event'] === null
                ? 'No flood event linked to this revision'
                : 'Event: '.$doc['event']['title'],
            null,
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
        );
        $section->addText(
            'For the period '.$doc['meta']['window_starts_at'].' to '.$doc['meta']['window_ends_at'].' ('.$doc['meta']['timezone'].')',
            null,
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
        );
        $section->addText('As of '.$doc['meta']['snapshot_at'], null, ['alignment' => Jc::CENTER]);

        $section->addText('THRU: '.$doc['office']['thru']);
        $section->addText('FROM: '.$doc['office']['signatory'].', '.$doc['office']['signatory_title']);
        $section->addText($doc['adaptation'], ['size' => 9, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function overview(Section $section, array $doc): void
    {
        $section->addText('I. SITUATION OVERVIEW', ['bold' => true, 'size' => 11], ['spaceBefore' => 200]);

        foreach ($doc['sections']['weather'] as $row) {
            $section->addText($row['payload']['summary'] ?? '');
            $this->sourceLine($section, $row);
        }

        if ($doc['sections']['weather'] === []) {
            $this->unavailable($section, 'Weather bulletin: unavailable — no reviewed supplement was frozen into this revision.');
        }

        $section->addText(sprintf(
            'A total of %d flood report(s) were received for the window; %d validated report(s) with an observation time inside the window are included. Highest alert: %s; highest flood level: %s (qualitative tiers, not measured depth).',
            $doc['totals']['reports'],
            $doc['totals']['included_reports'],
            SitrepDocument::alertLabel($doc['totals']['alert_level']),
            SitrepDocument::floodLabel($doc['totals']['flood_level']),
        ));

        $section->addText('Flooding situation by barangay', ['bold' => true]);
        $table = $this->table($section);
        $this->headerRow($table, ['Barangay', 'Alert level', 'Flood level', 'Reports', 'Included', 'Affected families', 'Affected individuals']);

        foreach ($doc['barangays'] as $row) {
            $table->addRow();
            $table->addCell(2200)->addText($row['barangay'].($row['no_report_received'] ? ' (no report received)' : ''));
            $table->addCell(1400)->addText($row['alert_label']);
            $table->addCell(1400)->addText($row['flood_label']);
            $table->addCell(900)->addText((string) $row['reports']);
            $table->addCell(900)->addText((string) $row['included_reports']);
            $table->addCell(1500)->addText($this->figure($row['affected_families']));
            $table->addCell(1500)->addText($this->figure($row['affected_individuals']));
        }

        $section->addText('Impact figures: '.$doc['stage_two']['reason'], ['size' => 9]);

        $section->addText('River basin assessment', ['bold' => true]);
        $this->rowsOrUnavailable($section, $doc['sections']['station'], function (array $row) use ($section): void {
            $section->addText(($row['payload']['station'] ?? 'Station').': '.($row['payload']['reading'] ?? '').' '.($row['payload']['unit'] ?? '').' ('.($row['payload']['status'] ?? 'status unavailable').')');
            $this->sourceLine($section, $row);
        }, 'Station readings: unavailable — no reviewed supplement was frozen into this revision.');
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function effects(Section $section, array $doc): void
    {
        $section->addText('II. EFFECTS', ['bold' => true, 'size' => 11], ['spaceBefore' => 200]);

        $section->addText('a. Lifelines', ['bold' => true]);
        $kinds = ['roads_bridges' => 'i. Roads and bridges', 'power' => 'ii. Power', 'communications' => 'iii. Communications', 'water_supply' => 'iv. Water Supply'];

        foreach ($kinds as $kind => $heading) {
            $section->addText($heading, ['bold' => true]);
            $rows = array_values(array_filter(
                $doc['sections']['lifeline'],
                fn (array $row): bool => ($row['payload']['kind'] ?? 'other') === $kind,
            ));
            $this->rowsOrUnavailable($section, $rows, function (array $row) use ($section): void {
                $section->addText(($row['payload']['asset'] ?? 'Asset').' — '.($row['payload']['status'] ?? 'status unavailable'));
                $this->sourceLine($section, $row);
            }, 'Unavailable — no reviewed supplement was frozen into this revision.');
        }

        $section->addText('b. Pre-emptive evacuation', ['bold' => true]);
        $this->rowsOrUnavailable($section, $doc['sections']['evacuation'], function (array $row) use ($section): void {
            $section->addText(($row['barangay'] ?? 'Municipality-wide').': '.($row['payload']['families'] ?? 'families unavailable').' families / '.($row['payload']['persons'] ?? 'persons unavailable').' persons, '.($row['payload']['centers'] ?? 'centers unavailable').' center(s)');
            $this->sourceLine($section, $row);
        }, 'Pre-emptive evacuation: unavailable — no reviewed supplement was frozen into this revision.');

        $section->addText('c. Affected population and evacuees', ['bold' => true]);
        $table = $this->table($section);
        $table->addRow();
        $table->addCell(2200, ['vMerge' => 'restart', 'bgColor' => 'EEEEEE'])->addText('Barangay', ['bold' => true]);
        $table->addCell(3000, ['gridSpan' => 2, 'bgColor' => 'EEEEEE'])->addText('Affected (cumulative for the event)', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addCell(3000, ['gridSpan' => 2, 'bgColor' => 'EEEEEE'])->addText('Evacuees', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addRow();
        $table->addCell(2200, ['vMerge' => 'continue']);
        foreach (['Families', 'Individuals', 'Inside centers', 'Outside centers'] as $heading) {
            $table->addCell(1500, ['bgColor' => 'EEEEEE'])->addText($heading, ['bold' => true]);
        }

        foreach ($doc['barangays'] as $row) {
            $evacuees = null;

            foreach ($doc['sections']['evacuees'] as $candidate) {
                if ($candidate['barangay_id'] === $row['barangay_id']) {
                    $evacuees = $candidate;
                    break;
                }
            }

            $table->addRow();
            $table->addCell(2200)->addText($row['barangay']);
            $table->addCell(1500)->addText($this->figure($row['affected_families']));
            $table->addCell(1500)->addText($this->figure($row['affected_individuals']));
            $table->addCell(1500)->addText($this->figure($evacuees['payload']['inside'] ?? null));
            $table->addCell(1500)->addText($this->figure($evacuees['payload']['outside'] ?? null));
        }

        $table->addRow();
        $table->addCell(2200)->addText('Total', ['bold' => true]);
        $table->addCell(1500)->addText($this->figure($doc['stage_two']['affected_families']));
        $table->addCell(1500)->addText($this->figure($doc['stage_two']['affected_individuals']));
        $table->addCell(1500)->addText($this->figure($this->sumPayload($doc['sections']['evacuees'], 'inside')));
        $table->addCell(1500)->addText($this->figure($this->sumPayload($doc['sections']['evacuees'], 'outside')));

        $section->addText('d. Casualties (aggregate)', ['bold' => true]);
        $table = $this->table($section);
        $this->headerRow($table, ['Barangay', 'Dead (persons)', 'Injured (persons)']);

        if ($doc['sections']['casualties'] === []) {
            $table->addRow();
            $table->addCell(8200, ['gridSpan' => 3])->addText('Unavailable — no reviewed casualty supplement was frozen into this revision.', ['italic' => true]);
        }

        foreach ($doc['sections']['casualties'] as $row) {
            $table->addRow();
            $table->addCell(3400)->addText($row['barangay'] ?? 'Unspecified');
            $table->addCell(2400)->addText($this->figure($row['payload']['dead'] ?? null));
            $table->addCell(2400)->addText($this->figure($row['payload']['injured'] ?? null));
        }

        $section->addText('e. Damaged houses', ['bold' => true]);
        $table = $this->table($section);
        $this->headerRow($table, ['Barangay', 'Partially damaged', 'Totally damaged']);

        if ($doc['sections']['damaged_houses'] === []) {
            $table->addRow();
            $table->addCell(8200, ['gridSpan' => 3])->addText('Unavailable — no reviewed assessment was frozen into this revision.', ['italic' => true]);
        }

        foreach ($doc['sections']['damaged_houses'] as $row) {
            $table->addRow();
            $table->addCell(3400)->addText($row['barangay'] ?? 'Unspecified');
            $table->addCell(2400)->addText($this->figure($row['payload']['partial'] ?? null));
            $table->addCell(2400)->addText($this->figure($row['payload']['total'] ?? null));
        }

        $section->addText('f. Cost of damages', ['bold' => true]);
        $this->rowsOrUnavailable($section, $doc['sections']['damage_costs'], function (array $row) use ($section): void {
            $section->addText(($row['payload']['category'] ?? 'Category').': '.($row['payload']['currency'] ?? 'PHP').' '.($row['payload']['amount'] ?? 'amount unavailable').' ('.($row['payload']['estimate_status'] ?? 'estimate status unavailable').')');
            $this->sourceLine($section, $row);
        }, 'Cost of damages: unavailable — no reviewed assessment was frozen into this revision.');

        $section->addText('g. Other incidents monitored', ['bold' => true]);
        $this->rowsOrUnavailable($section, $doc['sections']['other_incidents'], function (array $row) use ($section): void {
            $section->addText(($row['payload']['incident'] ?? 'Incident').' — '.($row['payload']['status'] ?? 'status unavailable'));
            $this->sourceLine($section, $row);
        }, 'Unavailable — no reviewed incident supplement was frozen into this revision.');

        $section->addText('h. Assistance extended', ['bold' => true]);
        $this->rowsOrUnavailable($section, $doc['sections']['assistance'], function (array $row) use ($section): void {
            $section->addText(($row['payload']['agency'] ?? 'Agency').': '.($row['payload']['kind'] ?? 'assistance').' — '.($row['payload']['remarks'] ?? ''));
            $this->sourceLine($section, $row);
        }, 'Unavailable — no reviewed assistance supplement was frozen into this revision.');
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function actions(Section $section, array $doc): void
    {
        $section->addText('III. ACTIONS TAKEN', ['bold' => true, 'size' => 11], ['spaceBefore' => 200]);

        if ($doc['sections']['actions'] === []) {
            $this->unavailable($section, 'Unavailable — no reviewed action supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['actions'] as $index => $row) {
            $section->addText(($index + 1).'. '.($row['payload']['action'] ?? ''));
            $this->sourceLine($section, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function signatory(Section $section, array $doc): void
    {
        $section->addText($doc['office']['signatory'], ['bold' => true], ['alignment' => Jc::RIGHT, 'spaceBefore' => 500, 'spaceAfter' => 0]);
        $section->addText($doc['office']['signatory_title'], null, ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
        $section->addText(
            $doc['issuance'] === null
                ? 'Draft — not yet issued. Prepared by '.$doc['meta']['prepared_by_name'].' ('.$doc['meta']['prepared_by_role'].').'
                : 'Issued by '.$doc['issuance']['issued_by_name'].' ('.$doc['issuance']['issued_by_role'].') — '.$doc['issuance']['issued_at'],
            ['size' => 9],
            ['alignment' => Jc::RIGHT],
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function annex(Section $section, array $doc): void
    {
        $section->addTextBreak();
        $section->addText('Provenance annex (non-personal)', ['bold' => true, 'size' => 10]);
        $section->addText(sprintf(
            'Window %s – %s (%s, %s); snapshot %s; format version %s; revision %d. Status counts (%s): validated %d, pending %d, returned %d, rejected %d. Latency: %d sample(s), %d excluded for missing observation time; mean %s s, median %s s, max %s s.',
            $doc['meta']['window_starts_at'],
            $doc['meta']['window_ends_at'],
            $doc['meta']['cutoff_label'],
            $doc['meta']['timezone'],
            $doc['meta']['snapshot_at'],
            $doc['meta']['format_version'],
            $doc['meta']['revision'],
            $doc['status_counts']['basis'],
            $doc['status_counts']['validated'],
            $doc['status_counts']['pending'],
            $doc['status_counts']['returned'],
            $doc['status_counts']['rejected'],
            $doc['latency']['samples'],
            $doc['latency']['excluded_null_observation'],
            $doc['latency']['mean_seconds'] ?? 'unavailable',
            $doc['latency']['median_seconds'] ?? 'unavailable',
            $doc['latency']['max_seconds'] ?? 'unavailable',
        ), ['size' => 9]);

        if (! $doc['meta']['has_content']) {
            $this->unavailable($section, 'This revision predates the adapted format (version 1); supplement sections are unavailable and only stage-1 figures are shown.');
        }

        $table = $this->table($section);
        $this->headerRow($table, ['Report #', 'Alert', 'Flood level', 'Observed', 'Validated']);

        if ($doc['provenance'] === []) {
            $table->addRow();
            $table->addCell(8200, ['gridSpan' => 5])->addText('No validated report with an observation time was included in this window.', ['italic' => true]);
        }

        foreach ($doc['provenance'] as $row) {
            $table->addRow();
            $table->addCell(1000)->addText((string) $row['flood_report_id']);
            $table->addCell(1500)->addText($row['alert_label']);
            $table->addCell(1500)->addText($row['flood_label']);
            $table->addCell(2100)->addText($row['observed_at']);
            $table->addCell(2100)->addText($row['validated_at']);
        }
    }

    private function table(Section $section): Table
    {
        return $section->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 40,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ]);
    }

    /**
     * @param  list<string>  $headings
     */
    private function headerRow(Table $table, array $headings): void
    {
        $table->addRow();

        foreach ($headings as $heading) {
            $table->addCell(null, ['bgColor' => 'EEEEEE'])->addText($heading, ['bold' => true]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsOrUnavailable(Section $section, array $rows, callable $render, string $message): void
    {
        if ($rows === []) {
            $this->unavailable($section, $message);

            return;
        }

        foreach ($rows as $row) {
            $render($row);
        }
    }

    private function unavailable(Section $section, string $message): void
    {
        $section->addText($message, ['italic' => true, 'color' => '444444']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourceLine(Section $section, array $row): void
    {
        $section->addText(
            'Source: '.($row['source'] ?? 'Unavailable').($row['observed_at'] ? ' — observed '.$row['observed_at'] : ''),
            ['size' => 8, 'color' => '333333'],
            ['spaceAfter' => 80],
        );
    }

    private function figure(mixed $value): string
    {
        return $value === null ? 'Unavailable' : (string) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumPayload(array $rows, string $key): ?int
    {
        if ($rows === []) {
            return null;
        }

        return (int) array_sum(array_map(fn (array $row): int => (int) ($row['payload'][$key] ?? 0), $rows));
    }
}

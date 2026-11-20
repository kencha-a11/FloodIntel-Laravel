<?php

namespace App\Services;

use App\Support\SitrepDocument;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Tab;

/**
 * DOCX export of the adapted SITREP (D-147, G5: PDF + DOCX; format fidelity
 * pass 2026-11-20).
 *
 * Renders the same frozen view-model as the Blade preview — never a live
 * aggregate — reproducing the MDRRMO memorandum format on long-bond paper
 * (8.5 × 13 in) with 0.5 in margins: office seals and a Times New Roman
 * letterhead closed by a thick rule, an underlined addressee and tab-aligned
 * memorandum closed by a thin rule, Century Gothic body, and the template's
 * shaded section bands (blue Roman, green lettered, light-green sub). The
 * browser print path remains the PDF file of record (D-43); this renderer
 * produces the editable copy from identical content. Casualties stay
 * aggregate-only and there is no picture section (D-45); columns with no
 * frozen source render "Unavailable", never a guessed figure.
 */
final class SitrepDocxRenderer
{
    private const PAGE_WIDTH_TWIPS = 12240; // 8.5 in

    private const PAGE_HEIGHT_TWIPS = 18720; // 13 in

    private const MARGIN_TWIPS = 720; // 0.5 in

    private const CONTENT_WIDTH_TWIPS = 10800; // page width minus both margins

    private const BAND_ROMAN = 'bdd6ee';

    private const BAND_LETTER = 'a8d08d';

    private const BAND_SUB = 'c5e0b3';

    private const SEAL_HEIGHT_PX = 76; // ~0.8 in at 96 dpi

    /**
     * @param  array<string, mixed>  $doc  the SitrepDocument view-model
     */
    public function render(array $doc): PhpWord
    {
        // The CLI PHP may lack ext-zip; PclZip ships with PhpWord and
        // produces the same OOXML package.
        Settings::setZipClass(class_exists(\ZipArchive::class) ? Settings::ZIPARCHIVE : Settings::PCLZIP);

        $word = new PhpWord;
        $word->setDefaultFontName('Century Gothic');
        $word->setDefaultFontSize(12);

        $section = $word->addSection([
            'pageSizeW' => self::PAGE_WIDTH_TWIPS,
            'pageSizeH' => self::PAGE_HEIGHT_TWIPS,
            'marginTop' => self::MARGIN_TWIPS,
            'marginBottom' => self::MARGIN_TWIPS,
            'marginLeft' => self::MARGIN_TWIPS,
            'marginRight' => self::MARGIN_TWIPS,
        ]);

        $this->letterhead($section, $doc);
        $this->memorandum($section, $doc);
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
        $seals = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 60]);

        foreach (['seal_apalit.png', 'seal_philippines.png', 'seal_mdrrmo.png'] as $seal) {
            $seals->addImage(resource_path('sitrep/'.$seal), ['height' => self::SEAL_HEIGHT_PX]);
        }

        $letterhead = ['name' => 'Times New Roman', 'size' => 10];

        foreach (['country', 'province', 'municipality', 'unit', 'address', 'email'] as $line) {
            $text = $line === 'email' ? 'Email: '.$doc['office'][$line] : $doc['office'][$line];
            $font = in_array($line, ['municipality', 'unit'], true)
                ? array_merge($letterhead, ['bold' => true])
                : $letterhead;
            $section->addText($text, $font, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $this->rule($section, 12);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function memorandum(Section $section, array $doc): void
    {
        $section->addText(
            'MEMORANDUM FOR THE CHAIRPERSON, MDRRMC',
            ['bold' => true, 'underline' => true],
            ['spaceBefore' => 120, 'spaceAfter' => 60],
        );

        $rows = [
            'THRU' => $doc['office']['thru'],
            'FROM' => $doc['office']['signatory'],
            'SUBJECT' => 'Situational Report No. '.($doc['issuance']['report_number'] ?? 'XX (unissued draft)').' Re: '
                .($doc['event']['title'] ?? 'No flood event linked to this revision'),
            'DATE' => $doc['meta']['snapshot_as_of'],
        ];

        // Template tab stops: colon at 1440 twips, value at 2268 twips with a
        // hanging indent so wrapped subject lines align under the value.
        // setTabs only accepts Tab objects; plain arrays are silently dropped.
        $tabs = [new Tab('left', 1440), new Tab('left', 2268)];

        foreach ($rows as $label => $value) {
            $run = $section->addTextRun([
                'tabs' => $tabs,
                'indentation' => ['left' => 2268, 'hanging' => 2268],
                'spaceAfter' => 0,
            ]);
            $run->addText($label);
            $run->addText("\t");
            $run->addText(':');
            $run->addText("\t");
            $run->addText($value, ['bold' => true]);
        }

        $this->rule($section, 6);

        $section->addText($doc['adaptation'], ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function overview(Section $section, array $doc): void
    {
        $this->band($section, 'I. SITUATION OVERVIEW', self::BAND_ROMAN);
        $this->band($section, 'a. Weather Condition', self::BAND_LETTER);

        $this->rowsOrUnavailable($section, $doc['sections']['weather'], function (array $row) use ($section): void {
            $section->addText($row['payload']['summary'] ?? '');
            $this->sourceLine($section, $row);
        }, 'Weather bulletin: unavailable — no reviewed supplement was frozen into this revision.');
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function effects(Section $section, array $doc): void
    {
        $this->band($section, 'II. EFFECTS', self::BAND_ROMAN);

        $this->flooding($section, $doc);
        $this->riverBasin($section, $doc);
        $this->lifelines($section, $doc);
        $this->evacuation($section, $doc);
        $this->population($section, $doc);
        $this->casualties($section, $doc);
        $this->damagedHouses($section, $doc);
        $this->damageCosts($section, $doc);
        $this->otherIncidents($section, $doc);
        $this->assistance($section, $doc);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function flooding(Section $section, array $doc): void
    {
        $this->band($section, 'a. Flooding Situation', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['City/Municipality/Barangay', 'Depth of Flooding', 'Remarks']);

        foreach ($doc['barangays'] as $row) {
            $table->addRow();
            $table->addCell(3600)->addText($row['barangay'].($row['no_report_received'] ? ' (no report received)' : ''));
            $table->addCell(3600)->addText($row['flood_label']);
            $table->addCell(3600)->addText($row['alert_level'] === null ? 'Unavailable' : 'Alert: '.$row['alert_label']);
        }

        $section->addText('Source/s: MDRRMO Site Inspection & Barangay DRRM. '.$doc['stage_two']['reason'].' Depth is a qualitative tier from validated reports, not measured depth.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function riverBasin(Section $section, array $doc): void
    {
        $this->band($section, 'b. Pampanga River Basin Assessment Levels / Condition of Dikes and Creeks', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['Station', 'Water Level', 'Alert', 'Alarm', 'Critical']);

        if ($doc['sections']['station'] === []) {
            $this->emptyRow($table, 5, 'Station readings: unavailable — no reviewed supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['station'] as $row) {
            $level = ($row['payload']['reading'] ?? 'Unavailable').' '.($row['payload']['unit'] ?? '');

            if (! empty($row['payload']['status'])) {
                $level .= ' ('.$row['payload']['status'].')';
            }

            if ($row['observed_at']) {
                $level .= ' as of '.$row['observed_at'];
            }

            $table->addRow();
            $table->addCell(2400)->addText($row['payload']['station'] ?? 'Station');
            $table->addCell(3600)->addText(trim($level));
            $table->addCell(1600)->addText('Unavailable', ['italic' => true]);
            $table->addCell(1600)->addText('Unavailable', ['italic' => true]);
            $table->addCell(1600)->addText('Unavailable', ['italic' => true]);
        }

        $section->addText('Source/s: '.($doc['sections']['station'][0]['source'] ?? 'Barangay DRRM').'. Alert/Alarm/Critical threshold columns are unavailable — no reviewed threshold supplement was frozen into this revision.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function lifelines(Section $section, array $doc): void
    {
        $this->band($section, 'c. Status of Lifelines (Source/s)', self::BAND_LETTER);

        $kinds = ['roads_bridges' => 'i. Roads and Bridges', 'power' => 'ii. Power Interruption', 'communications' => 'iii. Communications', 'water_supply' => 'iv. Water Supply'];
        $grouped = [];

        foreach ($doc['sections']['lifeline'] as $row) {
            $grouped[$row['payload']['kind'] ?? 'other'][] = $row;
        }

        foreach ($kinds as $kind => $heading) {
            $this->band($section, $heading, self::BAND_SUB);
            $rows = $grouped[$kind] ?? [];
            $table = $this->table($section);

            if ($kind === 'roads_bridges') {
                $this->headerRow($table, ['Roads / Bridges', 'Situation/Status', 'Actions Taken/Remarks']);

                if ($rows === []) {
                    $this->emptyRow($table, 3, 'Unavailable — no reviewed supplement was frozen into this revision.');
                }

                foreach ($rows as $row) {
                    $asset = ($row['payload']['asset'] ?? 'Asset').(! empty($row['payload']['location']) ? ' ('.$row['payload']['location'].')' : '');
                    $table->addRow();
                    $table->addCell(4000)->addText($asset);
                    $table->addCell(3400)->addText($row['payload']['status'] ?? 'Unavailable');
                    $table->addCell(3400)->addText('Unavailable', ['italic' => true]);
                }
            } else {
                $this->headerRow($table, ['Affected City/Municipality/Barangay', 'Date and Time of Interruption'.($kind === 'power' ? ' / Outages' : ''), 'Date and Time Restored', 'Remarks']);

                if ($rows === []) {
                    $this->emptyRow($table, 4, 'Unavailable — no reviewed supplement was frozen into this revision.');
                }

                foreach ($rows as $row) {
                    $table->addRow();
                    $table->addCell(3200)->addText($row['payload']['location'] ?? ($row['payload']['asset'] ?? 'Unavailable'));
                    $table->addCell(2800)->addText('Unavailable', ['italic' => true]);
                    $table->addCell(2400)->addText('Unavailable', ['italic' => true]);
                    $table->addCell(2400)->addText($row['payload']['status'] ?? 'Unavailable');
                }
            }
        }

        $section->addText('Source/s: MDRRMO & utility offices. Interruption/restoration time columns are unavailable — no reviewed time fields were frozen into this revision.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function evacuation(Section $section, array $doc): void
    {
        $this->band($section, 'd. Pre-emptive Evacuation', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['City/Municipality/Barangay', 'No. of Barangays', 'No. of Families and Persons', 'No. of Evacuation Centers', 'Date of Pre-emptive Evacuation', 'Remarks']);

        if ($doc['sections']['evacuation'] === []) {
            $this->emptyRow($table, 6, 'Pre-emptive evacuation: unavailable — no reviewed supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['evacuation'] as $row) {
            $table->addRow();
            $table->addCell(2200)->addText($row['barangay'] ?? 'Municipality-wide');
            $table->addCell(1400)->addText('Unavailable', ['italic' => true]);
            $table->addCell(2600)->addText(($row['payload']['families'] ?? 'Unavailable').' families / '.($row['payload']['persons'] ?? 'Unavailable').' persons');
            $table->addCell(1600)->addText($this->figure($row['payload']['centers'] ?? null));
            $table->addCell(1800)->addText($row['observed_at'] ?? 'Unavailable');
            $table->addCell(1200)->addText('Unavailable', ['italic' => true]);
        }

        $section->addText('Source/s: '.($doc['sections']['evacuation'][0]['source'] ?? 'Unavailable').'.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function population(Section $section, array $doc): void
    {
        $this->band($section, 'e. Affected Population', self::BAND_LETTER);

        $table = $this->table($section);
        $table->addRow();
        $table->addCell(2200, ['vMerge' => 'restart'])->addText('Barangay', ['bold' => true]);
        $table->addCell(1400, ['vMerge' => 'restart'])->addText('No. of Evac. Centers', ['bold' => true]);
        $table->addCell(7200, ['gridSpan' => 4])->addText('No. of Evacuees', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addRow();
        $table->addCell(2200, ['vMerge' => 'continue']);
        $table->addCell(1400, ['vMerge' => 'continue']);
        $table->addCell(3600, ['gridSpan' => 2])->addText('Inside Evacuation Center', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addCell(3600, ['gridSpan' => 2])->addText('Outside Evacuation Center', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addRow();
        $table->addCell(2200, ['vMerge' => 'continue']);
        $table->addCell(1400, ['vMerge' => 'continue']);
        foreach (['Families', 'Persons', 'Families', 'Persons'] as $heading) {
            $table->addCell(1800)->addText($heading, ['bold' => true]);
        }

        foreach ($doc['barangays'] as $row) {
            $evacuees = $this->evacueesFor($doc, $row['barangay_id']);
            $table->addRow();
            $table->addCell(2200)->addText($row['barangay']);
            $table->addCell(1400)->addText('Unavailable', ['italic' => true]);
            $table->addCell(1800)->addText('Unavailable', ['italic' => true]);
            $table->addCell(1800)->addText($this->figure($evacuees['payload']['inside'] ?? null));
            $table->addCell(1800)->addText('Unavailable', ['italic' => true]);
            $table->addCell(1800)->addText($this->figure($evacuees['payload']['outside'] ?? null));
        }

        $table = $this->table($section);
        $table->addRow();
        $table->addCell(3000, ['vMerge' => 'restart'])->addText('Barangay', ['bold' => true]);
        $table->addCell(7800, ['gridSpan' => 3])->addText('Affected Population', ['bold' => true], ['alignment' => Jc::CENTER]);
        $table->addRow();
        $table->addCell(3000, ['vMerge' => 'continue']);
        $table->addCell(2400)->addText('No. of HH', ['bold' => true]);
        $table->addCell(2700)->addText('No. of Families', ['bold' => true]);
        $table->addCell(2700)->addText('No. of Individuals', ['bold' => true]);

        foreach ($doc['barangays'] as $row) {
            $table->addRow();
            $table->addCell(3000)->addText($row['barangay']);
            $table->addCell(2400)->addText('Unavailable', ['italic' => true]);
            $table->addCell(2700)->addText($this->figure($row['affected_families']));
            $table->addCell(2700)->addText($this->figure($row['affected_individuals']));
        }

        $section->addText(
            'Source/s: MSWD/BDRRMC. '.$doc['stage_two']['reason'].' Household (HH) counts and the evacuee families/persons split are unavailable — no reviewed source was frozen into this revision.'
            .' Municipal totals: '.$this->figure($this->sumPayload($doc['sections']['evacuees'], 'inside')).' inside / '.$this->figure($this->sumPayload($doc['sections']['evacuees'], 'outside')).' outside evacuees;'
            .' '.$this->figure($doc['stage_two']['affected_families']).' families / '.$this->figure($doc['stage_two']['affected_individuals']).' individuals affected.',
            ['size' => 10, 'italic' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function casualties(Section $section, array $doc): void
    {
        $this->band($section, 'f. Casualties (Dead / Injured)', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['City/Municipality/Barangay', 'Dead (persons)', 'Injured (persons)']);

        if ($doc['sections']['casualties'] === []) {
            $this->emptyRow($table, 3, 'Unavailable — no reviewed casualty supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['casualties'] as $row) {
            $table->addRow();
            $table->addCell(4400)->addText($row['barangay'] ?? 'Unspecified');
            $table->addCell(3200)->addText($this->figure($row['payload']['dead'] ?? null));
            $table->addCell(3200)->addText($this->figure($row['payload']['injured'] ?? null));
        }

        $section->addText('Source/s: '.($doc['sections']['casualties'][0]['source'] ?? 'Unavailable').'. Aggregate-only (D-45): no individual identities.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function damagedHouses(Section $section, array $doc): void
    {
        $this->band($section, 'g. Damaged Houses', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['Barangay', 'No. of Partially Damaged', 'No. of Totally Damaged']);

        if ($doc['sections']['damaged_houses'] === []) {
            $this->emptyRow($table, 3, 'Unavailable — no reviewed assessment was frozen into this revision.');
        }

        foreach ($doc['sections']['damaged_houses'] as $row) {
            $table->addRow();
            $table->addCell(4400)->addText($row['barangay'] ?? 'Unspecified');
            $table->addCell(3200)->addText($this->figure($row['payload']['partial'] ?? null));
            $table->addCell(3200)->addText($this->figure($row['payload']['total'] ?? null));
        }

        $section->addText('Source/s: M/BDRRMO.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function damageCosts(Section $section, array $doc): void
    {
        $this->band($section, 'h. Cost of Damages', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['Damaged', 'Cost of Damages', 'Remarks']);

        foreach ($doc['cost_taxonomy'] as $row) {
            $table->addRow();

            if ($row['group']) {
                $table->addCell(self::CONTENT_WIDTH_TWIPS, ['gridSpan' => 3, 'bgColor' => 'F5F5F5'])->addText($row['label'], ['bold' => true]);

                continue;
            }

            $table->addCell(5000)->addText(($row['indent'] ? '- ' : '').$row['label'], null, $row['indent'] ? ['indentation' => ['left' => 200]] : []);
            $table->addCell(3000)->addText($row['cost'] ?? 'Unavailable', $row['cost'] === null ? ['italic' => true] : []);
            $table->addCell(2800)->addText($row['remarks'] ?? 'Unavailable', $row['remarks'] === null ? ['italic' => true] : []);
        }

        $section->addText('Source/s: DA Office / MDRRMO assessment. Fixed template taxonomy; a category with no reviewed supplement renders unavailable.', ['size' => 10, 'italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function otherIncidents(Section $section, array $doc): void
    {
        $this->band($section, 'i. Other Incidents/Activities Monitored', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['Incident / Activity', 'Status', 'Remarks']);

        if ($doc['sections']['other_incidents'] === []) {
            $this->emptyRow($table, 3, 'Unavailable — no reviewed incident supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['other_incidents'] as $row) {
            $table->addRow();
            $table->addCell(4400)->addText($row['payload']['incident'] ?? 'Incident');
            $table->addCell(3200)->addText($row['payload']['status'] ?? 'Unavailable');
            $table->addCell(3200)->addText('Unavailable', ['italic' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function assistance(Section $section, array $doc): void
    {
        $this->band($section, 'j. Cost/Kind of Assistance Extended', self::BAND_LETTER);
        $table = $this->table($section);
        $this->headerRow($table, ['Agency / Office', 'Cost/Kind of Assistance', 'Remarks']);

        if ($doc['sections']['assistance'] === []) {
            $this->emptyRow($table, 3, 'Unavailable — no reviewed assistance supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['assistance'] as $row) {
            $table->addRow();
            $table->addCell(3600)->addText($row['payload']['agency'] ?? 'Agency');
            $table->addCell(3600)->addText($row['payload']['kind'] ?? 'assistance');
            $table->addCell(3600)->addText($row['payload']['remarks'] ?? 'Unavailable');
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function actions(Section $section, array $doc): void
    {
        $this->band($section, 'III. ACTIONS TAKEN', self::BAND_ROMAN);

        if ($doc['sections']['actions'] === []) {
            $this->unavailable($section, 'Unavailable — no reviewed action supplement was frozen into this revision.');
        }

        foreach ($doc['sections']['actions'] as $index => $row) {
            $section->addText(($index + 1).'. '.($row['payload']['action'] ?? ''));
            $this->sourceLine($section, $row);
        }

        $section->addText('For the information of the Chairperson, MDRRMC, Municipal Administrator and MDRRMC Members.', null, ['spaceBefore' => 200]);
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
            ['size' => 10],
            ['alignment' => Jc::RIGHT],
        );
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function annex(Section $section, array $doc): void
    {
        $section->addTextBreak();
        $section->addText('Provenance annex (non-personal)', ['bold' => true, 'size' => 11]);
        $section->addText(sprintf(
            'A total of %d flood report(s) were received for the window; %d validated report(s) with an observation time inside the window are included. Highest alert: %s; highest flood level: %s (qualitative tiers, not measured depth).',
            $doc['totals']['reports'],
            $doc['totals']['included_reports'],
            SitrepDocument::alertLabel($doc['totals']['alert_level']),
            SitrepDocument::floodLabel($doc['totals']['flood_level']),
        ), ['size' => 10]);
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
        ), ['size' => 10]);

        if (! $doc['meta']['has_content']) {
            $this->unavailable($section, 'This revision predates the adapted format (version 1); supplement sections are unavailable and only stage-1 figures are shown.');
        }

        $table = $this->table($section);
        $this->headerRow($table, ['Report #', 'Alert', 'Flood level', 'Observed', 'Validated']);

        if ($doc['provenance'] === []) {
            $this->emptyRow($table, 5, 'No validated report with an observation time was included in this window.');
        }

        foreach ($doc['provenance'] as $row) {
            $table->addRow();
            $table->addCell(1400)->addText((string) $row['flood_report_id']);
            $table->addCell(2000)->addText($row['alert_label']);
            $table->addCell(2000)->addText($row['flood_label']);
            $table->addCell(2700)->addText($row['observed_at']);
            $table->addCell(2700)->addText($row['validated_at']);
        }
    }

    /**
     * A full-width shaded heading bar matching the template (blue Roman,
     * green lettered, light-green sub). Paragraph shading fills the line.
     */
    private function band(Section $section, string $text, string $fill): void
    {
        $section->addText($text, ['bold' => true], [
            'shading' => $fill,
            'spaceBefore' => 120,
            'spaceAfter' => 60,
        ]);
    }

    /**
     * A horizontal rule: a single-cell table carrying only a bottom border
     * (PhpWord paragraph styles expose no border in this version).
     */
    private function rule(Section $section, int $size): void
    {
        $table = $section->addTable([
            'borderSize' => 0,
            'borderBottomSize' => $size,
            'borderBottomColor' => '000000',
            'cellMargin' => 0,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ]);
        $table->addRow();
        $table->addCell(self::CONTENT_WIDTH_TWIPS)->addText('');
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
            $table->addCell(null)->addText($heading, ['bold' => true]);
        }
    }

    private function emptyRow(Table $table, int $span, string $message): void
    {
        $table->addRow();
        $table->addCell(self::CONTENT_WIDTH_TWIPS, ['gridSpan' => $span])->addText($message, ['italic' => true]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private function evacueesFor(array $doc, int $barangayId): ?array
    {
        foreach ($doc['sections']['evacuees'] as $candidate) {
            if ($candidate['barangay_id'] === $barangayId) {
                return $candidate;
            }
        }

        return null;
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
            'Source/s: '.($row['source'] ?? 'Unavailable').($row['observed_at'] ? ' — observed '.$row['observed_at'] : ''),
            ['size' => 10, 'italic' => true, 'color' => '333333'],
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

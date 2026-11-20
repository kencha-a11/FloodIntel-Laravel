<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
{{-- Authenticity marker: the client refuses to frame any 200 response that
     lacks it, so a broken or out-of-date API answering with an error page or
     the SPA shell can never run inline scripts inside the preview frame. --}}
<meta name="sitrep-document" content="{{ $doc['meta']['format_version'] }}">
<title>SITREP — {{ $doc['meta']['cutoff_label'] }} (Revision {{ $doc['meta']['revision'] }})</title>
<style>
    /* Geometry and type measured from the office PDF export
       (resources/sitrep/reference/mdrrmo-sitrep-template.html, pdf2htmlEX):
       8.5in x 13in page, 0.5in margins, Century Gothic body at 11pt/12pt,
       Times New Roman letterhead, 1.5pt letterhead rule, 1pt memorandum rule,
       flush-stacked shaded bands indented 18/54/99pt, fixed-width tables
       indented 55.5pt (lettered sections) or 91.5pt (sub-sections). */
    @page { size: 8.5in 13in; margin: 0.5in; }
    * { box-sizing: border-box; }
    body { font-family: 'Century Gothic', 'Apple Gothic', 'URW Gothic', sans-serif; font-size: 11pt; color: #000; margin: 0; padding: 0.5in; background: #fff; }
    .sheet { max-width: 542.5pt; margin: 0 auto; }
    /* Letterhead: seals, Times New Roman; country/province/municipality/unit
       bold at 10/10/11/12pt, address italic, email underlined; 1.5pt rule. */
    .letterhead { font-family: 'Times New Roman', Times, serif; font-size: 10pt; font-weight: bold; text-align: center; line-height: 1.35; border-bottom: 1.5pt solid #000; padding-bottom: 5pt; margin-bottom: 10pt; }
    .letterhead .municipality { font-size: 11pt; }
    .letterhead .unit { font-size: 12pt; }
    .letterhead .address { font-weight: normal; font-style: italic; }
    .letterhead .email { font-weight: normal; text-decoration: underline; }
    .seals { display: flex; justify-content: center; align-items: center; gap: 28px; margin-bottom: 4pt; }
    .seals img { height: 65px; width: auto; }
    /* Memorandum: 12pt, underlined addressee, regular labels with bold values,
       tab stops colon at 1440 twips and value at 2268 twips, 8pt row spacing,
       1pt rule closing the block. */
    .memorandum { font-size: 12pt; line-height: 15.9pt; border-bottom: 1pt solid #000; padding-bottom: 4pt; margin-bottom: 0; }
    .memorandum .addressee { font-weight: bold; text-decoration: underline; margin: 0 0 8pt; }
    .memo-row { display: flex; margin: 0 0 8pt; }
    .memo-row.memo-last { margin-bottom: 0; }
    .memo-label { flex: 0 0 96px; }
    .memo-colon { flex: 0 0 55px; }
    .memo-value { flex: 1; font-weight: bold; }
    /* Section bands: 11pt bold on the template shading (Roman = blue, lettered
       = green, sub-subsection = light green), 14.5pt tall, stacked flush. */
    .band { font-size: 11pt; font-weight: bold; line-height: 10.5pt; margin: 0; padding: 2pt 2pt 2pt 1.5pt; }
    .band-roman { background: #bdd6ee; margin-left: 18pt; margin-top: 12pt; }
    .band-letter { background: #a8d08d; margin-left: 54pt; margin-top: 12pt; }
    .band-sub { background: #c5e0b3; margin-left: 99pt; }
    .band-roman + .band-letter { margin-top: 0; }
    h2.band, h3.band, h4.band { margin-top: 0; margin-bottom: 0; }
    h2.band-roman { margin-top: 12pt; }
    h3.band-letter { margin-top: 12pt; }
    h2.band-roman + h3.band-letter { margin-top: 0; }
    p { margin: 6pt 0; line-height: 1.4; }
    .para-indent { margin-left: 55.5pt; }
    /* Tables: hairline borders, centred bold headers, fixed template widths,
       37.5pt single-row headers, 28pt stacked header rows, 23pt body rows. */
    table { border-collapse: collapse; margin: 8pt 0 0 55.5pt; max-width: calc(100% - 55.5pt); font-size: 11pt; }
    table.tbl-sub { margin-left: 91.5pt; max-width: calc(100% - 91.5pt); }
    th, td { border: 0.5pt solid #000; padding: 2pt 5pt; line-height: 1.4; text-align: left; vertical-align: middle; }
    th { background: #fff; font-weight: bold; text-align: center; }
    thead tr:only-child { height: 37.5pt; }
    thead tr { height: 28pt; }
    tbody tr { height: 23pt; }
    .num { text-align: right; }
    .center { text-align: center; }
    .unavailable { color: #444; font-style: italic; }
    /* Source/s lines: bold italic 10pt, indented past the lettered tables. */
    .source { font-size: 10pt; font-weight: bold; font-style: italic; color: #000; margin: 6pt 0 0 73.5pt; }
    .source-flush { margin-left: 55.5pt; }
    .group-row td { font-weight: normal; }
    td.indent { padding-left: 22pt; }
    .closing { font-weight: bold; margin-top: 24pt; }
    .signatory { margin-top: 30pt; text-align: center; }
    .signatory .name { font-weight: bold; font-size: 12pt; text-transform: uppercase; }
    .signatory .title { font-style: italic; }
    .signatory .source { margin-left: 0; }
    .annex { margin-top: 24pt; border-top: 1.5pt solid #000; padding-top: 8pt; }
    .annex h2 { font-size: 11pt; }
    .annex table, .annex p { font-size: 10pt; }
    .annex table { margin-left: 0; max-width: 100%; }
    .adaptation { font-size: 10pt; border: 1px solid #000; padding: 6pt; margin: 10pt 0; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    @media print { body { padding: 0; } .sheet { max-width: none; } }
</style>
</head>
<body>
<div class="sheet">

    @php
        // Inline the office seals as data URIs so the framed document is
        // self-contained (the preview CSP allows img-src data:) and renders
        // identically on every read.
        $seal = fn (string $file): string => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('sitrep/'.$file)));
    @endphp

    <div class="letterhead">
        <div class="seals">
            <img src="{{ $seal('seal_apalit.png') }}" alt="Seal of the Municipality of Apalit">
            <img src="{{ $seal('seal_philippines.png') }}" alt="Philippine national emblem">
            <img src="{{ $seal('seal_mdrrmo.png') }}" alt="MDRRMO Apalit emblem">
        </div>
        <div>{{ $doc['office']['country'] }}</div>
        <div>{{ $doc['office']['province'] }}</div>
        <div class="municipality">{{ $doc['office']['municipality'] }}</div>
        <div class="unit">{{ $doc['office']['unit'] }}</div>
        <div class="address">{{ $doc['office']['address'] }}</div>
        <div class="email">Email: {{ $doc['office']['email'] }}</div>
    </div>

    <div class="memorandum">
        <p class="addressee">MEMORANDUM FOR THE CHAIRPERSON, MDRRMC</p>
        <div class="memo-row"><span class="memo-label">THRU</span><span class="memo-colon">:</span><span class="memo-value">{{ $doc['office']['thru'] }}</span></div>
        <div class="memo-row"><span class="memo-label">FROM</span><span class="memo-colon">:</span><span class="memo-value">{{ $doc['office']['signatory'] }}</span></div>
        <div class="memo-row"><span class="memo-label">SUBJECT</span><span class="memo-colon">:</span><span class="memo-value">Situational Report No. {{ $doc['issuance']['report_number'] ?? 'XX (unissued draft)' }} Re:
            @if ($doc['event']){{ $doc['event']['title'] }}@else<span class="unavailable">No flood event linked to this revision</span>@endif</span></div>
        <div class="memo-row memo-last"><span class="memo-label">DATE</span><span class="memo-colon">:</span><span class="memo-value">{{ $doc['meta']['snapshot_as_of'] }}</span></div>
    </div>

    <div class="adaptation">{{ $doc['adaptation'] }}</div>

    <h2 class="band band-roman">I. SITUATION OVERVIEW</h2>
    <h3 class="band band-letter">a. Weather Condition</h3>
    @forelse ($doc['sections']['weather'] as $row)
        <p class="para-indent">{{ $row['payload']['summary'] ?? '' }}</p>
        <p class="source">Source/s: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Weather bulletin: unavailable — no reviewed supplement was frozen into this revision.</p>
    @endforelse

    <h2 class="band band-roman">II. EFFECTS</h2>

    <h3 class="band band-letter">a. Flooding Situation</h3>
    <table style="width: 460pt;">
        <colgroup><col style="width: 34.5%;"><col style="width: 37.2%;"><col style="width: 28.3%;"></colgroup>
        <thead>
            <tr>
                <th>City/Municipality/Barangay</th>
                <th>Depth of Flooding</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['barangays'] as $row)
                <tr>
                    <td>{{ $row['barangay'] }}@if ($row['no_report_received']) <span class="unavailable">(no report received)</span>@endif</td>
                    <td>{{ $row['flood_label'] }}</td>
                    <td>@if ($row['alert_level'] === null)<span class="unavailable">Unavailable</span>@else Alert: {{ $row['alert_label'] }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="source">Source/s: MDRRMO Site Inspection &amp; Barangay DRRM. {{ $doc['stage_two']['reason'] }} Depth is a qualitative tier from validated reports, not measured depth.</p>

    <h3 class="band band-letter">b. Pampanga River Basin Assessment Levels / Condition of Dikes and Creeks</h3>
    <table style="width: 460pt;">
        <colgroup><col style="width: 17.6%;"><col style="width: 17.4%;"><col style="width: 29.8%;"><col style="width: 14.7%;"><col style="width: 20.5%;"></colgroup>
        <thead>
            <tr>
                <th>Station</th>
                <th>Water Level</th>
                <th>Alert</th>
                <th>Alarm</th>
                <th>Critical</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['station'] as $row)
                <tr>
                    <td>{{ $row['payload']['station'] ?? 'Station' }}</td>
                    <td>{{ $row['payload']['reading'] ?? 'Unavailable' }} {{ $row['payload']['unit'] ?? '' }}@if (!empty($row['payload']['status'])) ({{ $row['payload']['status'] }})@endif @if ($row['observed_at']) as of {{ $row['observed_at'] }}@endif</td>
                    <td class="unavailable">Unavailable</td>
                    <td class="unavailable">Unavailable</td>
                    <td class="unavailable">Unavailable</td>
                </tr>
            @empty
                <tr><td colspan="5" class="unavailable">Station readings: unavailable — no reviewed supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="source">Source/s: {{ $doc['sections']['station'][0]['source'] ?? 'Barangay DRRM' }}. Alert/Alarm/Critical threshold columns are unavailable — no reviewed threshold supplement was frozen into this revision.</p>

    <h3 class="band band-letter">c. Status of Lifelines (Source/s)</h3>
    @php
        $lifelines = collect($doc['sections']['lifeline'])->groupBy(fn ($row) => $row['payload']['kind'] ?? 'other');
    @endphp

    <h4 class="band band-sub">i. Roads and Bridges</h4>
    <table class="tbl-sub" style="width: 362pt;">
        <colgroup><col style="width: 29.6%;"><col style="width: 35.4%;"><col style="width: 35%;"></colgroup>
        <thead>
            <tr><th>Roads / Bridges</th><th>Situation/Status</th><th>Actions Taken/Remarks</th></tr>
        </thead>
        <tbody>
            @forelse ($lifelines['roads_bridges'] ?? [] as $row)
                <tr>
                    <td>{{ $row['payload']['asset'] ?? 'Asset' }}@if (!empty($row['payload']['location'])) ({{ $row['payload']['location'] }})@endif</td>
                    <td>{{ $row['payload']['status'] ?? 'Unavailable' }}</td>
                    <td class="unavailable">Unavailable</td>
                </tr>
            @empty
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>

    @foreach (['power' => 'ii. Power Interruption', 'communications' => 'iii. Communications', 'water_supply' => 'iv. Water Supply'] as $kind => $heading)
        <h4 class="band band-sub">{{ $heading }}</h4>
        <table class="tbl-sub" style="width: 362pt;">
            <colgroup><col style="width: 31.7%;"><col style="width: 24.8%;"><col style="width: 21.9%;"><col style="width: 21.6%;"></colgroup>
            <thead>
                <tr>
                    <th>Affected City/Municipality/Barangay</th>
                    <th>Date and Time of Interruption{{ $kind === 'power' ? ' / Outages' : '' }}</th>
                    <th>Date and Time Restored</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lifelines[$kind] ?? [] as $row)
                    <tr>
                        <td>{{ $row['payload']['location'] ?? ($row['payload']['asset'] ?? 'Unavailable') }}</td>
                        <td class="unavailable">Unavailable</td>
                        <td class="unavailable">Unavailable</td>
                        <td>{{ $row['payload']['status'] ?? 'Unavailable' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="unavailable">Unavailable — no reviewed supplement was frozen into this revision.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
    <p class="source">Source/s: MDRRMO &amp; utility offices. Interruption/restoration time columns are unavailable — no reviewed time fields were frozen into this revision.</p>

    <h3 class="band band-letter">d. Pre-emptive Evacuation</h3>
    <table style="width: 487pt;">
        <colgroup><col style="width: 17.6%;"><col style="width: 12.9%;"><col style="width: 11.1%;"><col style="width: 13.9%;"><col style="width: 15%;"><col style="width: 29.5%;"></colgroup>
        <thead>
            <tr>
                <th>City/Municipality/Barangay</th>
                <th class="num">No. of Barangays</th>
                <th>No. of Families and Persons</th>
                <th class="num">No. of Evacuation Centers</th>
                <th>Date of Pre-emptive Evacuation</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['evacuation'] as $row)
                <tr>
                    <td>{{ $row['barangay'] ?? 'Municipality-wide' }}</td>
                    <td class="num unavailable">Unavailable</td>
                    <td>{{ $row['payload']['families'] ?? 'Unavailable' }} families / {{ $row['payload']['persons'] ?? 'Unavailable' }} persons</td>
                    <td class="num">{{ $row['payload']['centers'] ?? 'Unavailable' }}</td>
                    <td>{{ $row['observed_at'] ?? 'Unavailable' }}</td>
                    <td class="unavailable">Unavailable</td>
                </tr>
            @empty
                <tr><td colspan="6" class="unavailable">Pre-emptive evacuation: unavailable — no reviewed supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="source">Source/s: {{ $doc['sections']['evacuation'][0]['source'] ?? 'Unavailable' }}.</p>

    <h3 class="band band-letter">e. Affected Population</h3>
    <table style="width: 478pt;">
        <colgroup><col style="width: 17.2%;"><col style="width: 11.3%;"><col style="width: 19.8%;"><col style="width: 20.7%;"><col style="width: 15.1%;"><col style="width: 15.9%;"></colgroup>
        <thead>
            <tr>
                <th rowspan="3">Barangay</th>
                <th rowspan="3" class="num">No. of Evac. Centers</th>
                <th colspan="4" class="center">No. of Evacuees</th>
            </tr>
            <tr>
                <th colspan="2" class="center">Inside Evacuation Center</th>
                <th colspan="2" class="center">Outside Evacuation Center</th>
            </tr>
            <tr>
                <th class="num">Families</th>
                <th class="num">Persons</th>
                <th class="num">Families</th>
                <th class="num">Persons</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['barangays'] as $row)
                @php
                    $evacuees = collect($doc['sections']['evacuees'])->firstWhere('barangay_id', $row['barangay_id']);
                @endphp
                <tr>
                    <td>{{ $row['barangay'] }}</td>
                    <td class="num unavailable">Unavailable</td>
                    <td class="num unavailable">Unavailable</td>
                    <td class="num">@if (is_null($evacuees['payload']['inside'] ?? null))<span class="unavailable">Unavailable</span>@else{{ $evacuees['payload']['inside'] }}@endif</td>
                    <td class="num unavailable">Unavailable</td>
                    <td class="num">@if (is_null($evacuees['payload']['outside'] ?? null))<span class="unavailable">Unavailable</span>@else{{ $evacuees['payload']['outside'] }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width: 486pt;">
        <colgroup><col style="width: 16.7%;"><col style="width: 27.8%;"><col style="width: 27.8%;"><col style="width: 27.7%;"></colgroup>
        <thead>
            <tr>
                <th rowspan="2">Barangay</th>
                <th colspan="3" class="center">Affected Population</th>
            </tr>
            <tr>
                <th class="num">No. of HH</th>
                <th class="num">No. of Families</th>
                <th class="num">No. of Individuals</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['barangays'] as $row)
                <tr>
                    <td>{{ $row['barangay'] }}</td>
                    <td class="num unavailable">Unavailable</td>
                    <td class="num">@if (is_null($row['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_families'] }}@endif</td>
                    <td class="num">@if (is_null($row['affected_individuals']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_individuals'] }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="source source-flush">Source/s: MSWD/BDRRMC. {{ $doc['stage_two']['reason'] }} Household (HH) counts and the evacuee families/persons split are unavailable — no reviewed source was frozen into this revision.
        Municipal totals: @if ($doc['sections']['evacuees'] === [])<span class="unavailable">Unavailable</span>@else{{ collect($doc['sections']['evacuees'])->sum(fn ($r) => (int) ($r['payload']['inside'] ?? 0)).' inside / '.collect($doc['sections']['evacuees'])->sum(fn ($r) => (int) ($r['payload']['outside'] ?? 0)).' outside evacuees' }}@endif;
        @if (is_null($doc['stage_two']['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $doc['stage_two']['affected_families'].' families / '.$doc['stage_two']['affected_individuals'].' individuals' }}@endif affected.</p>

    <h3 class="band band-letter">f. Casualties (Dead / Injured)</h3>
    <table style="width: 396.5pt;">
        <colgroup><col style="width: 40%;"><col style="width: 30%;"><col style="width: 30%;"></colgroup>
        <thead>
            <tr><th>City/Municipality/Barangay</th><th class="num">Dead (persons)</th><th class="num">Injured (persons)</th></tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['casualties'] as $row)
                <tr>
                    <td>{{ $row['barangay'] ?? 'Unspecified' }}</td>
                    <td class="num">{{ $row['payload']['dead'] ?? 'Unavailable' }}</td>
                    <td class="num">{{ $row['payload']['injured'] ?? 'Unavailable' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed casualty supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="source">Source/s: {{ $doc['sections']['casualties'][0]['source'] ?? 'Unavailable' }}. Aggregate-only (D-45): no individual identities.</p>

    <h3 class="band band-letter">g. Damaged Houses</h3>
    <table style="width: 395pt;">
        <colgroup><col style="width: 33.3%;"><col style="width: 33.4%;"><col style="width: 33.3%;"></colgroup>
        <thead>
            <tr><th>Barangay</th><th class="num">No. of Partially Damaged</th><th class="num">No. of Totally Damaged</th></tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['damaged_houses'] as $row)
                <tr>
                    <td>{{ $row['barangay'] ?? 'Unspecified' }}</td>
                    <td class="num">{{ $row['payload']['partial'] ?? 'Unavailable' }}</td>
                    <td class="num">{{ $row['payload']['total'] ?? 'Unavailable' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed assessment was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="source">Source/s: M/BDRRMO.</p>

    <h3 class="band band-letter">h. Cost of Damages</h3>
    <table style="width: 486pt;">
        <colgroup><col style="width: 36.7%;"><col style="width: 25.5%;"><col style="width: 37.8%;"></colgroup>
        <thead>
            <tr><th>Damaged</th><th class="num">Cost of Damages</th><th>Remarks</th></tr>
        </thead>
        <tbody>
            @foreach ($doc['cost_taxonomy'] as $row)
                @if ($row['group'])
                    <tr class="group-row"><td colspan="3">{{ $row['label'] }}</td></tr>
                @else
                    <tr>
                        <td @if ($row['indent']) class="indent" @endif>@if ($row['indent'])- @endif{{ $row['label'] }}</td>
                        <td class="num">@if (is_null($row['cost']))<span class="unavailable">Unavailable</span>@else{{ $row['cost'] }}@endif</td>
                        <td>@if (is_null($row['remarks']))<span class="unavailable">Unavailable</span>@else{{ $row['remarks'] }}@endif</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
    <p class="source">Source/s: DA Office / MDRRMO assessment. Fixed template taxonomy; a category with no reviewed supplement renders unavailable.</p>

    <h3 class="band band-letter">i. Other Incidents/Activities Monitored</h3>
    <table style="width: 395.5pt;">
        <colgroup><col style="width: 33%;"><col style="width: 34%;"><col style="width: 33%;"></colgroup>
        <thead>
            <tr><th>Incident / Activity</th><th>Status</th><th>Remarks</th></tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['other_incidents'] as $row)
                <tr>
                    <td>{{ $row['payload']['incident'] ?? 'Incident' }}</td>
                    <td>{{ $row['payload']['status'] ?? 'Unavailable' }}</td>
                    <td class="unavailable">Unavailable</td>
                </tr>
            @empty
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed incident supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="band band-letter">j. Cost/Kind of Assistance Extended</h3>
    <table style="width: 395.5pt;">
        <colgroup><col style="width: 33%;"><col style="width: 34%;"><col style="width: 33%;"></colgroup>
        <thead>
            <tr><th>Agency / Office</th><th>Cost/Kind of Assistance</th><th>Remarks</th></tr>
        </thead>
        <tbody>
            @forelse ($doc['sections']['assistance'] as $row)
                <tr>
                    <td>{{ $row['payload']['agency'] ?? 'Agency' }}</td>
                    <td>{{ $row['payload']['kind'] ?? 'assistance' }}</td>
                    <td>{{ $row['payload']['remarks'] ?? 'Unavailable' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed assistance supplement was frozen into this revision.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="band band-roman">III. ACTIONS TAKEN</h2>
    @forelse ($doc['sections']['actions'] as $row)
        <p class="para-indent">{{ $loop->iteration }}. {{ $row['payload']['action'] ?? '' }}</p>
        <p class="source">Source/s: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Unavailable — no reviewed action supplement was frozen into this revision.</p>
    @endforelse

    <p class="closing">For the information of the Chairperson, MDRRMC, Municipal Administrator and MDRRMC Members.</p>

    <div class="signatory">
        <p class="name">{{ $doc['office']['signatory'] }}</p>
        <p class="title">{{ $doc['office']['signatory_title'] }}</p>
        @if ($doc['issuance'])
            <p class="source">Issued by {{ $doc['issuance']['issued_by_name'] }} ({{ $doc['issuance']['issued_by_role'] }}) — {{ $doc['issuance']['issued_at'] }}</p>
        @else
            <p class="source">Draft — not yet issued. Prepared by {{ $doc['meta']['prepared_by_name'] }} ({{ $doc['meta']['prepared_by_role'] }}).</p>
        @endif
    </div>

    <div class="annex">
        <h2>Provenance annex (non-personal)</h2>
        <p>
            A total of {{ $doc['totals']['reports'] }} flood report(s) were received for the window;
            {{ $doc['totals']['included_reports'] }} validated report(s) with an observation time inside the window are included.
            Highest alert: {{ \App\Support\SitrepDocument::alertLabel($doc['totals']['alert_level']) }};
            highest flood level: {{ \App\Support\SitrepDocument::floodLabel($doc['totals']['flood_level']) }} (qualitative tiers, not measured depth).
        </p>
        <p>
            Window {{ $doc['meta']['window_starts_at'] }} – {{ $doc['meta']['window_ends_at'] }} ({{ $doc['meta']['cutoff_label'] }}, {{ $doc['meta']['timezone'] }});
            snapshot {{ $doc['meta']['snapshot_at'] }}; format version {{ $doc['meta']['format_version'] }}; revision {{ $doc['meta']['revision'] }}.
            Status counts ({{ $doc['status_counts']['basis'] }}): validated {{ $doc['status_counts']['validated'] }}, pending {{ $doc['status_counts']['pending'] }}, returned {{ $doc['status_counts']['returned'] }}, rejected {{ $doc['status_counts']['rejected'] }}.
            Receipt-to-observation latency: {{ $doc['latency']['samples'] }} sample(s), {{ $doc['latency']['excluded_null_observation'] }} excluded for missing observation time;
            mean {{ $doc['latency']['mean_seconds'] ?? 'unavailable' }} s, median {{ $doc['latency']['median_seconds'] ?? 'unavailable' }} s, max {{ $doc['latency']['max_seconds'] ?? 'unavailable' }} s.
        </p>
        @if (! $doc['meta']['has_content'])
            <p class="unavailable">This revision predates the adapted format (version 1); supplement sections are unavailable and only stage-1 figures are shown.</p>
        @endif
        <table>
            <thead>
                <tr><th>Report #</th><th>Alert</th><th>Flood level</th><th>Observed</th><th>Validated</th></tr>
            </thead>
            <tbody>
                @forelse ($doc['provenance'] as $row)
                    <tr>
                        <td>{{ $row['flood_report_id'] }}</td>
                        <td>{{ $row['alert_label'] }}</td>
                        <td>{{ $row['flood_label'] }}</td>
                        <td>{{ $row['observed_at'] }}</td>
                        <td>{{ $row['validated_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="unavailable">No validated report with an observation time was included in this window.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
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
    @page { size: 8.5in 13in; margin: 0.5in; }
    * { box-sizing: border-box; }
    body { font-family: 'Century Gothic', 'Apple Gothic', 'URW Gothic', sans-serif; font-size: 12pt; color: #000; margin: 0; padding: 24px; background: #fff; }
    .sheet { max-width: 7.5in; margin: 0 auto; }
    /* Letterhead mirrors word/header1.xml: seals, Times New Roman 10pt,
       and a thick rule closing the block. */
    .letterhead { font-family: 'Times New Roman', Times, serif; font-size: 10pt; text-align: center; line-height: 1.3; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 12px; }
    .letterhead .municipality { font-weight: bold; }
    .letterhead .unit { font-weight: bold; }
    .seals { display: flex; justify-content: center; align-items: center; gap: 20px; margin-bottom: 6px; }
    .seals img { height: 78px; width: auto; }
    /* Memorandum: underlined addressee, label/value columns, thin rule. */
    .memorandum { border-bottom: 1px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
    .memorandum .addressee { font-weight: bold; text-decoration: underline; margin: 6px 0; }
    .memo-row { display: flex; margin: 3px 0; }
    .memo-label { flex: 0 0 92px; }
    .memo-value { flex: 1; font-weight: bold; }
    .meta-line { margin: 2px 0; }
    /* Section bands mirror the template shading: Roman = blue, lettered =
       green, sub-subsection = light green. */
    .band { font-size: 12pt; font-weight: bold; margin: 12px 0 6px; padding: 3px 8px; }
    .band-roman { background: #bdd6ee; }
    .band-letter { background: #a8d08d; }
    .band-sub { background: #c5e0b3; }
    h2.band { margin: 16px 0 6px; }
    p { margin: 4px 0; line-height: 1.4; }
    table { border-collapse: collapse; width: 100%; margin: 6px 0; }
    th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; vertical-align: top; }
    th { background: #fff; font-weight: bold; }
    .num { text-align: right; }
    .center { text-align: center; }
    .unavailable { color: #444; font-style: italic; }
    .source { font-size: 10pt; color: #333; margin: 2px 0 8px; font-style: italic; }
    .group-row td { background: #f5f5f5; font-weight: bold; }
    td.indent { padding-left: 18px; }
    .signatory { margin-top: 36px; text-align: right; }
    .signatory .name { font-weight: bold; text-transform: uppercase; }
    .closing { margin-top: 18px; }
    .annex { margin-top: 24px; border-top: 2px solid #000; padding-top: 8px; }
    .annex h2 { font-size: 11pt; }
    .annex table, .annex p { font-size: 10pt; }
    .adaptation { font-size: 10pt; border: 1px solid #000; padding: 6px; margin: 10px 0; }
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
        <div>{{ $doc['office']['address'] }}</div>
        <div>Email: {{ $doc['office']['email'] }}</div>
    </div>

    <div class="memorandum">
        <p class="addressee">MEMORANDUM FOR THE CHAIRPERSON, MDRRMC</p>
        <div class="memo-row"><span class="memo-label">THRU:</span><span class="memo-value">{{ $doc['office']['thru'] }}</span></div>
        <div class="memo-row"><span class="memo-label">FROM:</span><span class="memo-value">{{ $doc['office']['signatory'] }}, {{ $doc['office']['signatory_title'] }}</span></div>
        <div class="memo-row"><span class="memo-label">SUBJECT:</span><span class="memo-value">Situational Report No. {{ $doc['issuance']['report_number'] ?? 'XX (unissued draft)' }} Re:
            @if ($doc['event']){{ $doc['event']['title'] }}@else<span class="unavailable">No flood event linked to this revision</span>@endif</span></div>
        <div class="memo-row"><span class="memo-label">DATE:</span><span class="memo-value">{{ $doc['meta']['snapshot_at'] }} ({{ $doc['meta']['timezone'] }})</span></div>
        <p class="meta-line">For the period {{ $doc['meta']['window_starts_at'] }} to {{ $doc['meta']['window_ends_at'] }}</p>
    </div>

    <div class="adaptation">{{ $doc['adaptation'] }}</div>

    <h2 class="band band-roman">I. SITUATION OVERVIEW</h2>
    <h3 class="band band-letter">a. Weather Condition</h3>
    @forelse ($doc['sections']['weather'] as $row)
        <p>{{ $row['payload']['summary'] ?? '' }}</p>
        <p class="source">Source/s: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Weather bulletin: unavailable — no reviewed supplement was frozen into this revision.</p>
    @endforelse

    <h2 class="band band-roman">II. EFFECTS</h2>

    <h3 class="band band-letter">a. Flooding Situation</h3>
    <table>
        <thead>
            <tr>
                <th>City/Municipality/Barangay</th>
                <th>Depth of Flooding (qualitative tier)</th>
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
    <table>
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
    <p class="source">Source/s: Barangay DRRM. Alert/Alarm/Critical threshold columns are unavailable — no reviewed threshold supplement was frozen into this revision.</p>

    <h3 class="band band-letter">c. Status of Lifelines</h3>
    @php
        $lifelines = collect($doc['sections']['lifeline'])->groupBy(fn ($row) => $row['payload']['kind'] ?? 'other');
    @endphp

    <h4 class="band band-sub">i. Roads and Bridges</h4>
    <table>
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
        <table>
            <thead>
                <tr>
                    <th>Affected City/Municipality/Barangay</th>
                    <th>Date and Time of Interruption / Outages</th>
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
    <table>
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

    <h3 class="band band-letter">e. Affected Population</h3>
    <h4 class="band band-sub">Evacuees</h4>
    <table>
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
            <tr>
                <th>Total</th>
                <td class="num unavailable">Unavailable</td>
                <td class="num unavailable">Unavailable</td>
                <td class="num">@if ($doc['sections']['evacuees'] === [])<span class="unavailable">Unavailable</span>@else{{ collect($doc['sections']['evacuees'])->sum(fn ($r) => (int) ($r['payload']['inside'] ?? 0)) }}@endif</td>
                <td class="num unavailable">Unavailable</td>
                <td class="num">@if ($doc['sections']['evacuees'] === [])<span class="unavailable">Unavailable</span>@else{{ collect($doc['sections']['evacuees'])->sum(fn ($r) => (int) ($r['payload']['outside'] ?? 0)) }}@endif</td>
            </tr>
        </tbody>
    </table>

    <h4 class="band band-sub">Affected Population</h4>
    <table>
        <thead>
            <tr>
                <th>Barangay</th>
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
            <tr>
                <th>Total</th>
                <td class="num unavailable">Unavailable</td>
                <td class="num">@if (is_null($doc['stage_two']['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $doc['stage_two']['affected_families'] }}@endif</td>
                <td class="num">@if (is_null($doc['stage_two']['affected_individuals']))<span class="unavailable">Unavailable</span>@else{{ $doc['stage_two']['affected_individuals'] }}@endif</td>
            </tr>
        </tbody>
    </table>
    <p class="source">Source/s: MSWD/BDRRMC. {{ $doc['stage_two']['reason'] }} Household (HH) counts and the evacuee families/persons split are unavailable — no reviewed source was frozen into this revision.</p>

    <h3 class="band band-letter">f. Casualties (Dead / Injured)</h3>
    <table>
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
                <tr><td colspan="3" class="unavailable">Unavailable — no reviewed casualty supplement was frozen into this revision. Aggregate-only (D-45): no individual identities.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 class="band band-letter">g. Damaged Houses</h3>
    <table>
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
    <table>
        <thead>
            <tr><th>Damaged</th><th class="num">Cost of Damages</th><th>Remarks</th></tr>
        </thead>
        <tbody>
            @foreach ($doc['cost_taxonomy'] as $row)
                @if ($row['group'])
                    <tr class="group-row"><td colspan="3">{{ $row['label'] }}</td></tr>
                @else
                    <tr>
                        <td @if ($row['indent']) class="indent" @endif>{{ $row['label'] }}</td>
                        <td class="num">@if (is_null($row['cost']))<span class="unavailable">Unavailable</span>@else{{ $row['cost'] }}@endif</td>
                        <td>@if (is_null($row['remarks']))<span class="unavailable">Unavailable</span>@else{{ $row['remarks'] }}@endif</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
    <p class="source">Source/s: DA Office / MDRRMO assessment. Fixed template taxonomy; a category with no reviewed supplement renders unavailable.</p>

    <h3 class="band band-letter">i. Other Incidents/Activities Monitored</h3>
    <table>
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
    <table>
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
        <p>{{ $loop->iteration }}. {{ $row['payload']['action'] ?? '' }}</p>
        <p class="source">Source/s: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Unavailable — no reviewed action supplement was frozen into this revision.</p>
    @endforelse

    <p class="closing">For the information of the Chairperson, MDRRMC, Municipal Administrator and MDRRMC Members.</p>

    <div class="signatory">
        <p class="name">{{ $doc['office']['signatory'] }}</p>
        <p>{{ $doc['office']['signatory_title'] }}</p>
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
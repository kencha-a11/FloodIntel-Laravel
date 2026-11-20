<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SITREP — {{ $doc['meta']['cutoff_label'] }} (Revision {{ $doc['meta']['revision'] }})</title>
<style>
    @page { size: 8.5in 13in; margin: 0.5in; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000; margin: 0; padding: 24px; background: #fff; }
    .sheet { max-width: 7.5in; margin: 0 auto; }
    .letterhead { text-align: center; line-height: 1.35; margin-bottom: 12px; }
    .letterhead .municipality { font-weight: bold; font-size: 13px; }
    .letterhead .unit { font-weight: bold; }
    h1 { font-size: 14px; text-align: center; margin: 14px 0 4px; }
    h2 { font-size: 12px; margin: 16px 0 6px; }
    h3 { font-size: 11px; margin: 10px 0 4px; }
    p { margin: 4px 0; line-height: 1.4; }
    table { border-collapse: collapse; width: 100%; margin: 6px 0; }
    th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; vertical-align: top; }
    th { background: #eee; font-weight: bold; }
    .num { text-align: right; }
    .meta-line { margin: 2px 0; }
    .routing { margin: 10px 0; }
    .unavailable { color: #444; font-style: italic; }
    .source { font-size: 10px; color: #333; margin: 2px 0 8px; }
    .signatory { margin-top: 36px; text-align: right; }
    .signatory .name { font-weight: bold; text-transform: uppercase; }
    .annex { margin-top: 24px; border-top: 2px solid #000; padding-top: 8px; }
    .annex h2 { font-size: 11px; }
    .annex table, .annex p { font-size: 10px; }
    .adaptation { font-size: 10px; border: 1px solid #000; padding: 6px; margin: 10px 0; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    @media print { body { padding: 0; } .sheet { max-width: none; } }
</style>
</head>
<body>
<div class="sheet">

    <div class="letterhead">
        <div>{{ $doc['office']['country'] }}</div>
        <div>{{ $doc['office']['province'] }}</div>
        <div class="municipality">{{ $doc['office']['municipality'] }}</div>
        <div class="unit">{{ $doc['office']['unit'] }}</div>
        <div>{{ $doc['office']['address'] }}</div>
        <div>{{ $doc['office']['email'] }}</div>
    </div>

    <h1>SITUATIONAL REPORT No. {{ $doc['issuance']['report_number'] ?? '— (unissued draft)' }}</h1>
    <p class="meta-line" style="text-align:center;">
        @if ($doc['event'])
            Event: {{ $doc['event']['title'] }}
        @else
            <span class="unavailable">No flood event linked to this revision</span>
        @endif
    </p>
    <p class="meta-line" style="text-align:center;">
        For the period {{ $doc['meta']['window_starts_at'] }} to {{ $doc['meta']['window_ends_at'] }} ({{ $doc['meta']['timezone'] }})
    </p>
    <p class="meta-line" style="text-align:center;">As of {{ $doc['meta']['snapshot_at'] }}</p>

    <div class="routing">
        <p class="meta-line"><strong>THRU:</strong> {{ $doc['office']['thru'] }}</p>
        <p class="meta-line"><strong>FROM:</strong> {{ $doc['office']['signatory'] }}, {{ $doc['office']['signatory_title'] }}</p>
    </div>

    <div class="adaptation">{{ $doc['adaptation'] }}</div>

    <h2>I. SITUATION OVERVIEW</h2>
    @forelse ($doc['sections']['weather'] as $row)
        <p>{{ $row['payload']['summary'] ?? '' }}</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Weather bulletin: unavailable — no reviewed supplement was frozen into this revision.</p>
    @endforelse

    <p>
        A total of {{ $doc['totals']['reports'] }} flood report(s) were received for the window;
        {{ $doc['totals']['included_reports'] }} validated report(s) with an observation time inside the window are included.
        Highest alert: {{ \App\Support\SitrepDocument::alertLabel($doc['totals']['alert_level']) }};
        highest flood level: {{ \App\Support\SitrepDocument::floodLabel($doc['totals']['flood_level']) }} (qualitative tiers, not measured depth).
    </p>

    <h3>Flooding situation by barangay</h3>
    <table>
        <thead>
            <tr>
                <th>Barangay</th>
                <th>Alert level</th>
                <th>Flood level</th>
                <th class="num">Reports</th>
                <th class="num">Included</th>
                <th class="num">Affected families</th>
                <th class="num">Affected individuals</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['barangays'] as $row)
                <tr>
                    <td>{{ $row['barangay'] }}@if ($row['no_report_received']) <span class="unavailable">(no report received)</span>@endif</td>
                    <td>{{ $row['alert_label'] }}</td>
                    <td>{{ $row['flood_label'] }}</td>
                    <td class="num">{{ $row['reports'] }}</td>
                    <td class="num">{{ $row['included_reports'] }}</td>
                    <td class="num">@if (is_null($row['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_families'] }}@endif</td>
                    <td class="num">@if (is_null($row['affected_individuals']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_individuals'] }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="source">Impact figures: {{ $doc['stage_two']['reason'] }}</p>

    <h3>River basin assessment</h3>
    @forelse ($doc['sections']['station'] as $row)
        <p>{{ $row['payload']['station'] ?? 'Station' }}: {{ $row['payload']['reading'] ?? '' }} {{ $row['payload']['unit'] ?? '' }} ({{ $row['payload']['status'] ?? 'status unavailable' }})</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Station readings: unavailable — no reviewed supplement was frozen into this revision.</p>
    @endforelse

    <h2>II. EFFECTS</h2>

    <h3>a. Lifelines</h3>
    @php
        $lifelineKinds = ['roads_bridges' => 'i. Roads and bridges', 'power' => 'ii. Power', 'communications' => 'iii. Communications', 'water_supply' => 'iv. Water Supply'];
        $lifelines = collect($doc['sections']['lifeline'])->groupBy(fn ($row) => $row['payload']['kind'] ?? 'other');
    @endphp
    @foreach ($lifelineKinds as $kind => $heading)
        <p><strong>{{ $heading }}</strong></p>
        @forelse ($lifelines[$kind] ?? [] as $row)
            <p>{{ $row['payload']['asset'] ?? 'Asset' }} — {{ $row['payload']['status'] ?? 'status unavailable' }}@if (!empty($row['payload']['location'])) ({{ $row['payload']['location'] }})@endif</p>
            <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
        @empty
            <p class="unavailable">Unavailable — no reviewed supplement was frozen into this revision.</p>
        @endforelse
    @endforeach

    <h3>b. Pre-emptive evacuation</h3>
    @forelse ($doc['sections']['evacuation'] as $row)
        <p>{{ $row['barangay'] ?? 'Municipality-wide' }}: {{ $row['payload']['families'] ?? 'families unavailable' }} families / {{ $row['payload']['persons'] ?? 'persons unavailable' }} persons, {{ $row['payload']['centers'] ?? 'centers unavailable' }} center(s)</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Pre-emptive evacuation: unavailable — no reviewed supplement was frozen into this revision.</p>
    @endforelse

    <h3>c. Affected population and evacuees</h3>
    <table>
        <thead>
            <tr>
                <th rowspan="2">Barangay</th>
                <th colspan="2" style="text-align:center;">Affected (cumulative for the event)</th>
                <th colspan="2" style="text-align:center;">Evacuees</th>
            </tr>
            <tr>
                <th class="num">Families</th>
                <th class="num">Individuals</th>
                <th class="num">Inside centers</th>
                <th class="num">Outside centers</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['barangays'] as $row)
                @php
                    $evacuees = collect($doc['sections']['evacuees'])->firstWhere('barangay_id', $row['barangay_id']);
                @endphp
                <tr>
                    <td>{{ $row['barangay'] }}</td>
                    <td class="num">@if (is_null($row['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_families'] }}@endif</td>
                    <td class="num">@if (is_null($row['affected_individuals']))<span class="unavailable">Unavailable</span>@else{{ $row['affected_individuals'] }}@endif</td>
                    <td class="num">{{ $evacuees['payload']['inside'] ?? 'Unavailable' }}</td>
                    <td class="num">{{ $evacuees['payload']['outside'] ?? 'Unavailable' }}</td>
                </tr>
            @endforeach
            <tr>
                <th>Total</th>
                <td class="num">@if (is_null($doc['stage_two']['affected_families']))<span class="unavailable">Unavailable</span>@else{{ $doc['stage_two']['affected_families'] }}@endif</td>
                <td class="num">@if (is_null($doc['stage_two']['affected_individuals']))<span class="unavailable">Unavailable</span>@else{{ $doc['stage_two']['affected_individuals'] }}@endif</td>
                <td class="num">@if ($doc['sections']['evacuees'] === [])<span class="unavailable">Unavailable</span>@else{{ collect($doc['sections']['evacuees'])->sum(fn ($row) => (int) ($row['payload']['inside'] ?? 0)) }}@endif</td>
                <td class="num">@if ($doc['sections']['evacuees'] === [])<span class="unavailable">Unavailable</span>@else{{ collect($doc['sections']['evacuees'])->sum(fn ($row) => (int) ($row['payload']['outside'] ?? 0)) }}@endif</td>
            </tr>
        </tbody>
    </table>

    <h3>d. Casualties (aggregate)</h3>
    <table>
        <thead>
            <tr><th>Barangay</th><th class="num">Dead (persons)</th><th class="num">Injured (persons)</th></tr>
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

    <h3>e. Damaged houses</h3>
    <table>
        <thead>
            <tr><th>Barangay</th><th class="num">Partially damaged</th><th class="num">Totally damaged</th></tr>
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

    <h3>f. Cost of damages</h3>
    @forelse ($doc['sections']['damage_costs'] as $row)
        <p>{{ $row['payload']['category'] ?? 'Category' }}: {{ $row['payload']['currency'] ?? 'PHP' }} {{ $row['payload']['amount'] ?? 'amount unavailable' }} ({{ $row['payload']['estimate_status'] ?? 'estimate status unavailable' }})</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — assessed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Cost of damages: unavailable — no reviewed assessment was frozen into this revision.</p>
    @endforelse

    <h3>g. Other incidents monitored</h3>
    @forelse ($doc['sections']['other_incidents'] as $row)
        <p>{{ $row['payload']['incident'] ?? 'Incident' }} — {{ $row['payload']['status'] ?? 'status unavailable' }}</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Unavailable — no reviewed incident supplement was frozen into this revision.</p>
    @endforelse

    <h3>h. Assistance extended</h3>
    @forelse ($doc['sections']['assistance'] as $row)
        <p>{{ $row['payload']['agency'] ?? 'Agency' }}: {{ $row['payload']['kind'] ?? 'assistance' }} — {{ $row['payload']['remarks'] ?? '' }}</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — observed {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Unavailable — no reviewed assistance supplement was frozen into this revision.</p>
    @endforelse

    <h2>III. ACTIONS TAKEN</h2>
    @forelse ($doc['sections']['actions'] as $row)
        <p>{{ $loop->iteration }}. {{ $row['payload']['action'] ?? '' }}</p>
        <p class="source">Source: {{ $row['source'] ?? 'Unavailable' }}@if ($row['observed_at']) — {{ $row['observed_at'] }}@endif</p>
    @empty
        <p class="unavailable">Unavailable — no reviewed action supplement was frozen into this revision.</p>
    @endforelse

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
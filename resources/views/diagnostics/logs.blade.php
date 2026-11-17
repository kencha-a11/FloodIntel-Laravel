<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production diagnostics · FloodIntel</title>
<style nonce="{{ $nonce }}">
* {
    box-sizing:border-box;
}
[hidden] {
    display:none!important;
}
body {
    margin:0;
    background:#f5f7fa;
    color:#172638;
    font:15px/1.55 system-ui,sans-serif;
}
main {
    max-width:1240px;
    margin:auto;
    padding:36px 28px;
}
header,.actions,.heading {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
header {
    margin-bottom:28px;
}
.brand {
    font-weight:750;
    letter-spacing:.08em;
    font-size:12px;
    color:#276c69;
    text-transform:uppercase;
}
h1 {
    font-size:clamp(25px,4vw,34px);
    letter-spacing:-.035em;
    margin:4px 0;
    line-height:1.25;
}
h2 {
    font-size:18px;
    margin:0;
}
p {
    margin:8px 0;
}
.muted,dt,label {
    color:#526174;
}
.tag {
    border:1px solid #d2dce5;
    border-radius:20px;
    padding:5px 12px;
    font-size:12px;
    background:white;
}
.panel {
    background:white;
    border:1px solid #dce3ea;
    border-radius:12px;
    padding:24px;
    margin-bottom:20px;
}
#unlockPanel {
    max-width:640px;
    margin:64px auto;
}
form {
    display:flex;
    align-items:end;
    gap:12px;
    margin:24px 0 16px;
}
.field {
    display:grid;
    gap:6px;
    flex:1;
    min-width:0;
}
label {
    font-size:13px;
    font-weight:600;
}
input,select,button {
    font:inherit;
    min-height:44px;
    border:1px solid #bac7d3;
    border-radius:7px;
    padding:9px 12px;
    max-width:100%;
}
input,select {
    width:100%;
    background:white;
    color:#172638;
    min-width:0;
}
button {
    background:white;
    color:#263c50;
    cursor:pointer;
    font-weight:600;
}
button:hover:not(:disabled) {
    background:#edf4f4;
    border-color:#276c69;
}
button.primary {
    background:#20645f;
    color:white;
}
button:disabled {
    opacity:.5;
    cursor:not-allowed;
}
:focus-visible {
    outline:3px solid #138781;
    outline-offset:3px;
}
.help,footer {
    font-size:13px;
    color:#526174;
}
#status {
    padding:12px 16px;
    border:1px solid #d2dce5;
    border-left:4px solid #277a74;
    border-radius:7px;
    background:white;
    margin-bottom:20px;
    overflow-wrap:anywhere;
}
#status.error {
    border-left-color:#a43832;
    background:#fff8f6;
}
dl {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:20px;
    margin:0;
}
dt {
    font-size:12px;
}
dd {
    margin:4px 0 0;
    font-size:14px;
    overflow-wrap:anywhere;
}
.filters {
    display:grid;
    grid-template-columns:2fr 1fr 1fr;
    gap:14px;
    margin:20px 0;
}
#scope,#count {
    font-size:13px;
    color:#526174;
}
.table-wrap {
    border:1px solid #dce3ea;
    border-radius:8px;
    overflow:hidden;
}
table {
    border-collapse:collapse;
    width:100%;
    table-layout:fixed;
}
th {
    background:#f6f8fa;
    color:#526174;
    font-size:12px;
    text-align:left;
    padding:12px 14px;
}
th:first-child {
    width:180px;
}
th:nth-child(2) {
    width:115px;
}
td {
    border-top:1px solid #e5ebef;
    padding:16px 14px;
    vertical-align:top;
    overflow-wrap:anywhere;
}
time,pre,.request-id {
    font:12px/1.6 ui-monospace,Consolas,monospace;
}
.level {
    display:inline-block;
    padding:3px 8px;
    border-radius:5px;
    font-size:11px;
    font-weight:750;
    text-transform:uppercase;
    background:#edf1f5;
    color:#425469;
}
.error.level,.critical.level,.alert.level,.emergency.level {
    background:#fff0ee;
    color:#a02e28;
}
.warning.level {
    background:#fff5dc;
    color:#805609;
}
.info.level,.notice.level {
    background:#e8f4f2;
    color:#20645f;
}
.message {
    white-space:pre-wrap;
    margin:0;
}
.request-id {
    color:#526174;
}
details {
    margin-top:10px;
}
summary {
    color:#286a67;
    cursor:pointer;
    font-size:13px;
}
pre {
    white-space:pre-wrap;
    overflow-wrap:anywhere;
    background:#f5f7fa;
    padding:14px;
    border-radius:6px;
    max-height:360px;
    overflow:auto;
}
.empty {
    text-align:center;
    padding:42px 16px;
    color:#526174;
}
.preservation {
    border-top:1px solid #e5ebef;
    padding-top:18px;
    margin-top:24px;
}
@media(max-width:760px) {
    main {
        padding:24px 16px;
    }
    .panel {
        padding:18px;
    }
    dl {
        grid-template-columns:1fr 1fr;
    }
    .filters {
        grid-template-columns:1fr;
    }
    form {
        flex-direction:column;
        align-items:stretch;
    }
    #unlockPanel {
        margin:32px auto;
    }
    table,tbody,tr,td {
        display:block;
        width:100%;
    }
    thead {
        display:none;
    }
    tr {
        padding:14px;
        border-top:1px solid #dce3ea;
    }
    tr:first-child {
        border:0;
    }
    td {
        padding:3px 0;
        border:0;
    }
    td:first-child,td:nth-child(2) {
        display:inline-block;
        width:auto;
        margin-right:12px;
    }
    td:nth-child(3) {
        padding-top:10px;
    }
}
@media(max-width:380px) {
    main {
        padding:20px 12px;
    }
    .panel {
        padding:14px;
    }
    dl {
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>
<main>
<header>
<div>
<div class="brand">FloodIntel / Operations</div>
<h1>Production diagnostics</h1>
<p class="muted">A focused view of recent application events.</p>
</div>
<span class="tag" id="accessState">Locked · temporary access</span>
</header>
<p id="status" role="status" aria-live="polite" aria-atomic="true">Enter an operator credential to load diagnostics. No logs are loaded while locked.</p>
<section class="panel" id="unlockPanel" aria-labelledby="unlockTitle">
<h2 id="unlockTitle">Unlock this session</h2>
<p class="muted">Use the temporary diagnostic credential provided by your deployment operator.</p>
<form id="unlockForm">
<div class="field">
<label for="credential">Diagnostic credential</label>
<input id="credential" type="password" autocomplete="off" spellcheck="false" required aria-describedby="credentialHelp">
</div>
<button class="primary" id="unlock" type="submit">Unlock logs</button>
</form>
<p class="help" id="credentialHelp">Sent in a request header, never in a URL. HTTPS is required in production. Held only in this page’s memory; locking or reloading forgets it.</p>
<p class="help">Access requires a configured expiry. Log clearing is disabled.</p>
<noscript>JavaScript is required to send the access header. No logs are included in this page.</noscript>
</section>
<div id="workspace" hidden>
<section class="panel" aria-label="Diagnostic source">
<dl>
<div>
<dt>Environment / release</dt>
<dd id="environment">—</dd>
</div>
<div>
<dt>Instance / source</dt>
<dd id="source">—</dd>
</div>
<div>
<dt>Last successful fetch</dt>
<dd id="fetched">—</dd>
</div>
<div>
<dt>Access expires</dt>
<dd id="expiry">—</dd>
</div>
</dl>
</section>
<section class="panel" aria-labelledby="eventsTitle">
<div class="heading">
<div>
<h2 id="eventsTitle">Recent events</h2>
<p id="count">
</p>
</div>
<div class="actions">
<button id="refresh" type="button">Refresh</button>
<button id="copyAll" type="button">Copy all loaded records</button>
<button id="copy" type="button">Copy filtered</button>
<button id="lock" type="button">Lock</button>
</div>
</div>
<div class="filters">
<div class="field">
<label for="search">Search loaded events</label>
<input type="search" id="search" placeholder="Message, details or request ID">
</div>
<div class="field">
<label for="severity">Severity</label>
<select id="severity">
<option value="all">All levels</option>
<option>emergency</option>
<option>alert</option>
<option>critical</option>
<option>error</option>
<option>warning</option>
<option>notice</option>
<option>info</option>
<option>debug</option>
<option value="unknown">Unparsed / unknown</option>
</select>
</div>
<div class="field">
<label for="period">Time window</label>
<select id="period">
<option value="all">All loaded times</option>
<option value="15">Last 15 minutes</option>
<option value="60">Last hour</option>
<option value="1440">Last 24 hours</option>
</select>
</div>
</div>
<p id="scope">
</p>
<div class="table-wrap">
<table aria-label="Recent application events">
<thead>
<tr>
<th scope="col">Timestamp</th>
<th scope="col">Severity</th>
<th scope="col">Event / details</th>
</tr>
</thead>
<tbody id="events">
</tbody>
</table>
<p class="empty" id="empty" hidden>
</p>
</div>
<p class="help preservation">
<strong>Evidence preservation</strong> · Log clearing is disabled. Copy all loaded records includes every loaded event and all available sanitized details, ignoring filters. The loaded window is limited to 200 events; older history and redacted content are not included. Review before sharing and attach to the relevant incident record.</p>
</section>
</div>
<footer>Local, instance-specific logs are ephemeral—not a durable archive. Manual refresh · per-instance rate limiting.</footer>
</main>
<script nonce="{{ $nonce }}">
(() => {
    const el = id => document.getElementById(id);
    let credential = '', snapshot = null, filtered = [], busy = false, generation = 0, expiryTimer;
    const status = (message, error = false) => { el('status').textContent = message; el('status').classList.toggle('error', error); };
    const node = (tag, text, className) => { const item = document.createElement(tag); item.textContent = text; if (className) item.className = className; return item; };
    const timestamp = value => /(?:Z|[+-]\d{2}:\d{2})$/.test(value) ? Date.parse(value) : NaN;
    const time = value => Number.isNaN(timestamp(value)) ? (value ? `${value} (timezone unknown)` : 'Unknown time') : new Date(value).toISOString().replace('T', ' ').replace('.000Z', ' UTC');
    function lock(message = 'Session locked. The credential and loaded events have been cleared.') {
        generation++; clearTimeout(expiryTimer); credential = ''; snapshot = null; filtered = [];
        el('credential').value = ''; el('events').replaceChildren(); el('workspace').hidden = true; el('unlockPanel').hidden = false;
        el('accessState').textContent = 'Locked · temporary access'; status(message); el('credential').focus();
    }
    async function copy(events, selection = 'event') {
        if (!snapshot || events.length === 0) return;
        try {
            await navigator.clipboard.writeText(JSON.stringify({ scope: snapshot.scope, source: snapshot.source, environment: snapshot.environment, instance: snapshot.instance, release: snapshot.release, fetched_at: snapshot.fetched_at, state: snapshot.state, truncated: snapshot.truncated, selection, loaded_count: snapshot.events.length, exported_count: events.length, filters: selection === 'filtered' ? { search_applied: el('search').value.length > 0, severity: el('severity').value, period_minutes: el('period').value } : null, events }, null, 2));
            status(`Copied ${events.length} sanitized event(s) with all available details${selection === 'all_loaded' ? ', ignoring filters' : ''}. Loaded window only; not complete log history.`);
        } catch { status('Clipboard unavailable. Expand an event and select its sanitized text to copy manually.', true); }
    }
    function render() {
        if (!snapshot) return;
        const query = el('search').value.toLowerCase(), severity = el('severity').value, period = el('period').value;
        const cutoff = Date.parse(snapshot.fetched_at) - Number(period) * 60000;
        filtered = snapshot.events.filter(event => (severity === 'all' || event.level === severity) && (period === 'all' || timestamp(event.time) >= cutoff) && [event.message, event.details, event.request_id].join(' ').toLowerCase().includes(query));
        el('events').replaceChildren();
        filtered.forEach(event => {
            const row = document.createElement('tr'), date = document.createElement('td'), level = document.createElement('td'), message = document.createElement('td');
            date.append(node('time', time(event.time)));
            const known = ['debug','info','notice','warning','error','critical','alert','emergency'].includes(event.level) ? event.level : 'unparsed';
            level.append(node('span', known, `level ${known}`)); message.append(node('p', event.message, 'message'));
            if (event.request_id) message.append(node('p', `Request / event ID: ${event.request_id}`, 'request-id'));
            const details = document.createElement('details'); details.append(node('summary', 'Inspect event'), node('pre', event.details || event.message));
            const button = node('button', 'Copy event'); button.type = 'button'; button.addEventListener('click', () => copy([event])); details.append(button); message.append(details);
            row.append(date, level, message); el('events').append(row);
        });
        el('count').textContent = `${filtered.length} shown / ${snapshot.events.length} loaded · newest first`;
        el('empty').hidden = filtered.length > 0;
        el('empty').textContent = snapshot.state === 'missing' ? 'No supported local log file was found. Check the configured logging channels.' : snapshot.events.length === 0 ? 'No complete events are available in this log window.' : 'No events match these filters. Search applies only to the loaded window.';
        el('copy').disabled = filtered.length === 0;
        el('copyAll').disabled = snapshot.events.length === 0;
    }
    async function load() {
        if (busy) return;
        busy = true; const current = generation; el('unlock').disabled = true; el('refresh').disabled = true;
        status(snapshot ? 'Refreshing… previous events remain visible until the request succeeds.' : 'Loading diagnostic events…');
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(location.pathname, { headers: { 'Accept': 'application/json', 'X-Diagnostics-Key': credential }, credentials: 'omit', cache: 'no-store', redirect: 'error', signal: controller.signal });
            const data = await response.json();
            if (current !== generation) return;
            if (response.status === 403) { lock(data.message || 'Access denied or expired.'); return; }
            if (!response.ok) throw new Error(response.status === 429 ? `Rate limit reached. Retry after ${response.headers.get('Retry-After') || '60'} seconds.` : data.message || 'Diagnostic source is unavailable.');
            const changed = snapshot && snapshot.source !== data.source;
            snapshot = data; el('credential').value = ''; el('unlockPanel').hidden = true; el('workspace').hidden = false; el('accessState').textContent = 'Unlocked · read only';
            el('environment').textContent = `${data.environment} / ${data.release}`; el('source').textContent = `${data.instance} / ${data.source}`;
            el('fetched').textContent = time(data.fetched_at); el('expiry').textContent = time(data.expires_at);
            el('scope').textContent = `${data.scope} ${data.truncated ? 'Truncated window: older or oversized content is omitted.' : ''} Times without a timezone are excluded by time filters.`;
            clearTimeout(expiryTimer);
            const expire = () => { const remaining = Date.parse(data.expires_at) - Date.now(); if (remaining <= 0) lock('Access has expired. Loaded events and the credential have been cleared.'); else expiryTimer = setTimeout(expire, Math.min(remaining, 2147483647)); };
            expire(); render(); if (snapshot) status(changed ? 'Log source changed after rotation. The current window is loaded.' : 'Snapshot loaded. Filters and copies use only this sanitized window.');
        } catch (error) {
            if (current === generation) status(`${snapshot ? 'Stale snapshot retained. ' : ''}${error.name === 'AbortError' ? 'Request timed out.' : error.message} Use ${snapshot ? 'Refresh' : 'Unlock logs'} to retry.`, true);
        } finally { clearTimeout(timeout); busy = false; el('unlock').disabled = false; el('refresh').disabled = false; }
    }
    el('unlockForm').addEventListener('submit', event => { event.preventDefault(); credential = el('credential').value; el('credential').value = ''; load(); });
    el('refresh').addEventListener('click', load); el('lock').addEventListener('click', () => lock()); el('copy').addEventListener('click', () => copy(filtered, 'filtered'));
    el('copyAll').addEventListener('click', () => copy(snapshot?.events || [], 'all_loaded'));
    ['search','severity','period'].forEach(id => el(id).addEventListener('input', render));
    window.addEventListener('pagehide', () => lock());
})();
</script>
</body>
</html>

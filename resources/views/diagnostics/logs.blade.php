<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FloodIntel — Server Logs</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 24px;
            background: #f8fafc;
            color: #0f172a;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.025em;
        }
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .stats {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            font-size: 0.875rem;
            color: #64748b;
        }
        .stats strong {
            color: #0f172a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            text-align: left;
            padding: 12px 16px;
            background: #f1f5f9;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Consolas, monospace;
            white-space: pre-wrap;
            word-break: break-word;
            vertical-align: top;
        }
        tr:last-child td {
            border-bottom: none;
        }
        tr:nth-child(even) td {
            background: #fafbfc;
        }
        .level-error {
            color: #dc2626;
            font-weight: 600;
        }
        .level-warning {
            color: #d97706;
            font-weight: 600;
        }
        .level-info {
            color: #2563eb;
            font-weight: 600;
        }
        .empty {
            padding: 40px 24px;
            text-align: center;
            color: #64748b;
        }
        .notice {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 0.875rem;
            margin-bottom: 16px;
        }
        a {
            color: #2563eb;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>FloodIntel — Server Logs</h1>
            <span class="badge">Diagnostics</span>
        </header>

        @if (isset($message))
            <div class="empty">{{ $message }}</div>
        @else
            <div class="notice">
                <strong>Public diagnostics endpoint.</strong> Protected only by a secret query key.
                Do not share this URL. Logs may contain IP addresses, user agents, and stack traces.
            </div>

            <div class="stats">
                <span><strong>{{ $shown }}</strong> of <strong>{{ $total }}</strong> total log lines shown (most recent first).</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 160px;">Time</th>
                        <th style="width: 100px;">Level</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line }}</td>
                            <td>
                                @php
                                    $lineStr = (string) $line;
                                @endphp
                                @if (str_contains($lineStr, 'ERROR')) <span class="level-error">ERROR</span>
                                @elseif (str_contains($lineStr, 'WARNING')) <span class="level-warning">WARNING</span>
                                @elseif (str_contains($lineStr, 'INFO')) <span class="level-info">INFO</span>
                                @else <span class="level-info">LOG</span>
                                @endif
                            </td>
                            <td>{{ $line }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (count($lines) === 0)
                <div class="empty">No log lines available.</div>
            @endif
        @endif

        <p style="margin-top: 32px; font-size: 0.8rem; color: #94a3b8;">
            FloodIntel Laravel · {{ config('app.env') }}
        </p>
    </div>
</body>
</html>

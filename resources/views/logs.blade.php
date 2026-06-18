<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Production Logs</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-neutral-950 text-neutral-200 font-mono min-h-screen p-6 selection:bg-emerald-500 selection:text-black">

    <div class="max-w-7xl mx-auto">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center border-b border-neutral-800 pb-4 mb-6 gap-4">
            <div>
                <h1 class="text-xl font-bold text-neutral-100 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
                    Render Console Stream Emulator
                </h1>
                @if(session('status'))
                    <p class="text-xs text-emerald-400 mt-1 font-bold">✓ {{ session('status') }}</p>
                @else
                    <p class="text-xs text-neutral-500 mt-1">Reading active environment context from live app runtime</p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                <button onclick="copyLogContents(this)" class="cursor-pointer bg-neutral-900 hover:bg-neutral-800 border border-neutral-800 text-neutral-300 text-xs px-4 py-2 rounded transition duration-150 flex items-center gap-2">
                    📋 <span class="btn-text">Copy Logs</span>
                </button>

                <button onclick="window.location.reload()" class="cursor-pointer bg-neutral-900 hover:bg-neutral-800 text-xs text-neutral-300 px-4 py-2 rounded border border-neutral-800 transition duration-150">
                    🔄 Refresh Stream
                </button>

                <form action="/logs/clear?secret={{ request()->query('secret') }}" method="POST" onsubmit="return confirm('Are you sure you want to completely truncate the current log buffers?');" class="m-0">
                    @csrf
                    <button type="submit" class="cursor-pointer bg-red-950/40 hover:bg-red-900/40 text-red-400 border border-red-900/60 text-xs px-4 py-2 rounded font-medium transition duration-150">
                        🗑️ Clear Logs
                    </button>
                </form>
            </div>
        </div>

        <div class="bg-neutral-900 border border-neutral-800 rounded-lg shadow-2xl overflow-hidden">
            <div class="bg-neutral-950 px-4 py-2 border-b border-neutral-800 flex items-center gap-2">
                <div class="w-2.5 h-2.5 rounded-full bg-red-500/60"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-yellow-500/60"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-green-500/60"></div>
                <span class="text-[11px] text-neutral-600 ml-2">laravel.log — Generated Live</span>
            </div>

            <div id="log-terminal-feed" class="divide-y divide-neutral-900 overflow-x-auto max-h-[75vh] p-2 space-y-1">
                @forelse($logs as $log)
                    @php
                        $levelClass = match($log['level']) {
                            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'bg-red-950/40 text-red-400 border-l-2 border-red-500',
                            'WARNING', 'NOTICE' => 'bg-amber-950/30 text-amber-400 border-l-2 border-amber-500',
                            'INFO' => 'bg-blue-950/20 text-blue-400 border-l-2 border-blue-500',
                            default => 'bg-neutral-950/20 text-neutral-400 border-l-2 border-neutral-700'
                        };

                        $badgeClass = match($log['level']) {
                            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'bg-red-500/20 text-red-400 border-red-500/30',
                            'WARNING', 'NOTICE' => 'bg-amber-500/20 text-amber-400 border-amber-500/30',
                            'INFO' => 'bg-blue-500/20 text-blue-400 border-blue-500/30',
                            default => 'bg-neutral-500/20 text-neutral-400 border-neutral-500/30'
                        };
                    @endphp

                    <div class="log-row flex items-start gap-4 p-2.5 rounded text-xs transition duration-150 tracking-wide {{ $levelClass }}">
                        <span class="log-time text-neutral-500 whitespace-nowrap shrink-0 selection:text-neutral-500">
                            {{ $log['timestamp'] ?: '-------------------' }}
                        </span>

                        <span class="log-level px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wider border font-mono shrink-0 {{ $badgeClass }}">
                            {{ Str::padRight($log['level'], 8, ' ') }}
                        </span>

                        <span class="log-msg break-all font-mono text-neutral-300 leading-relaxed">
                            {{ $log['message'] }}
                        </span>
                    </div>
                @empty
                    <div class="p-8 text-center text-neutral-600 text-sm">
                        No active log instances detected inside standard kernel boundaries.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <script>
        function copyLogContents(buttonElement) {
            const rowElements = document.querySelectorAll('#log-terminal-feed .log-row');
            if (rowElements.length === 0) return;

            let plainTextBuffer = "";

            rowElements.forEach(row => {
                const timeStr = row.querySelector('.log-time').innerText.trim();
                const levelStr = row.querySelector('.log-level').innerText.trim();
                const messageStr = row.querySelector('.log-msg').innerText.trim();

                // Reconstruct blocks back to uniform logfile string format styles
                if(timeStr === '-------------------') {
                     plainTextBuffer += `${messageStr}\n`;
                } else {
                     plainTextBuffer += `[${timeStr}] production.${levelStr}: ${messageStr}\n`;
                }
            });

            // Dispatch compiled raw string blocks safely to system clipboard boundaries
            navigator.clipboard.writeText(plainTextBuffer.trim()).then(() => {
                const textLabel = buttonElement.querySelector('.btn-text');
                const nativeMarkup = textLabel.innerText;

                // Visual confirmation trigger alert changes
                textLabel.innerText = "Copied String Data!";
                buttonElement.classList.remove('border-neutral-800');
                buttonElement.classList.add('border-emerald-500/60', 'text-emerald-400');

                setTimeout(() => {
                    textLabel.innerText = nativeMarkup;
                    buttonElement.classList.remove('border-emerald-500/60', 'text-emerald-400');
                    buttonElement.classList.add('border-neutral-800');
                }, 1800);
            }).catch(err => {
                console.error('Could not parse clipboard injection stream constraints: ', err);
            });
        }
    </script>
</body>
</html>

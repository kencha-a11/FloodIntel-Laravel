<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify Your Email - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

    <div class="max-w-md w-full bg-white rounded-3xl shadow-2xl shadow-indigo-100 border border-slate-100 p-8">

        <!-- Icon -->
        <div class="flex justify-center mb-6">
            <div class="w-20 h-20 bg-amber-100 rounded-full flex items-center justify-center">
                <svg class="w-10 h-10 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
            </div>
        </div>

        <!-- Title -->
        <h2 class="text-2xl font-bold text-center text-slate-800 mb-2">Verify Your Email Address</h2>
        <p class="text-center text-slate-500 text-sm mb-6">
            Please verify your email to continue
        </p>

        <!-- User Info Card -->
        <div class="bg-indigo-50 p-4 rounded-xl mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-indigo-600 uppercase font-bold">Logged in as</p>
                    <p class="text-sm font-semibold text-slate-800">{{ Auth::user()->name ?? 'User' }}</p>
                    <p class="text-xs text-slate-500">{{ Auth::user()->email ?? 'No email' }}</p>
                </div>
                <div class="bg-white px-3 py-1 rounded-full">
                    <p class="text-xs text-amber-600 font-semibold">⚠️ Unverified</p>
                </div>
            </div>
        </div>

        <!-- Status Messages -->
        @if(session('status'))
            <div
                class="mb-4 p-3 text-sm text-emerald-700 bg-emerald-50 rounded-xl border border-emerald-200 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ session('status') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 p-3 text-sm text-red-700 bg-red-50 rounded-xl border border-red-200 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ session('error') }}
            </div>
        @endif

        <!-- Instructions -->
        <div class="bg-slate-50 p-4 rounded-xl mb-6">
            <h3 class="font-semibold text-slate-700 text-sm mb-2 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
                What to do next:
            </h3>
            <ol class="text-xs text-slate-600 space-y-2 list-decimal list-inside">
                <li>Check your email inbox (or spam folder)</li>
                <li>Click the verification link sent to your email</li>
                <li>Return here and refresh the page</li>
            </ol>
        </div>

        <!-- Resend Button -->
        <form method="POST" action="{{ route('verification.send') }}" class="mb-4" id="resend-form">
            @csrf
            <button type="submit" id="resend-btn"
                class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                Resend Verification Email
            </button>
        </form>

        <!-- Resend Cooldown Timer -->
        <p id="cooldown-timer" class="text-center text-xs text-slate-400 mb-4"></p>

        <!-- Divider -->
        <div class="relative py-4">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-slate-200"></div>
            </div>
            <div class="relative flex justify-center text-xs text-slate-400 uppercase bg-white px-2">
                Need help?
            </div>
        </div>

        <!-- Logout Button -->
        <div class="text-center">
            <form method="POST" action="{{ route('server.logout') }}" class="inline">
                @csrf
                <button type="submit"
                    class="text-xs text-red-500 hover:text-red-700 transition flex items-center justify-center gap-1 w-full">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Logout
                </button>
            </form>
        </div>
    </div>

    <script>
        // Cooldown timer para sa resend button (60 seconds)
        let cooldown = 0;
        const resendBtn = document.getElementById('resend-btn');
        const timerEl = document.getElementById('cooldown-timer');

        // I-check kung may cooldown sa localStorage
        const lastResend = localStorage.getItem('last_resend_time_verify');
        if (lastResend) {
            const elapsed = Math.floor((Date.now() - parseInt(lastResend)) / 1000);
            if (elapsed < 60) {
                cooldown = 60 - elapsed;
                startCooldown();
            } else {
                localStorage.removeItem('last_resend_time_verify');
            }
        }

        function startCooldown() {
            if (cooldown <= 0) {
                if (resendBtn) {
                    resendBtn.disabled = false;
                    resendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
                if (timerEl) timerEl.textContent = '';
                localStorage.removeItem('last_resend_time_verify');
                return;
            }

            if (resendBtn) {
                resendBtn.disabled = true;
                resendBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
            if (timerEl) {
                const minutes = Math.floor(cooldown / 60);
                const seconds = cooldown % 60;
                if (minutes > 0) {
                    timerEl.textContent = `⏳ Wait ${minutes}m ${seconds}s before resending...`;
                } else {
                    timerEl.textContent = `⏳ Wait ${seconds} second${seconds > 1 ? 's' : ''} before resending...`;
                }
            }

            cooldown--;
            setTimeout(startCooldown, 1000);
        }

        // Kapag nag-submit ang resend form
        const resendForm = document.getElementById('resend-form');
        if (resendForm) {
            resendForm.addEventListener('submit', function (e) {
                if (resendBtn && resendBtn.disabled) {
                    e.preventDefault();
                    alert('Please wait before requesting another verification email.');
                    return false;
                }

                localStorage.setItem('last_resend_time_verify', Date.now().toString());
                cooldown = 60;
                startCooldown();
            });
        }

        // Auto-refresh instruction
        console.log('Verification page loaded. User: {{ Auth::user()->email ?? "N/A" }}');
    </script>
</body>

</html>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-slate-50 min-h-screen">

    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-slate-200 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-slate-800">Socialite<span
                            class="text-indigo-600">Auth</span></span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2 bg-slate-100 px-3 py-1.5 rounded-full">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span class="text-sm text-slate-600">
                            <strong
                                class="text-indigo-600 uppercase">{{ Auth::user()->provider_name ?? 'Email' }}</strong>
                        </span>
                    </div>

                    <form action="{{ route('server.logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit"
                            class="flex items-center space-x-2 px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-xl hover:bg-red-100 transition-all duration-200">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>Log Out</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto mt-8 p-6">

        <!-- Verification Alert -->
        <div class="mb-6">
            @if (!Auth::user()->hasVerifiedEmail())
                <div id="verification-alert"
                    class="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <p class="text-sm text-amber-800">
                            <strong>Email not verified:</strong> Please verify your email to access all features.
                        </p>
                    </div>
                    <form action="{{ route('verification.send') }}" method="POST">
                        @csrf
                        <button type="submit"
                            class="text-sm font-medium text-amber-800 bg-amber-100 px-4 py-2 rounded-xl hover:bg-amber-200 transition">
                            Resend Link
                        </button>
                    </form>
                </div>
            @else
                <div id="verification-alert"
                    class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center space-x-3">
                    <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd" />
                    </svg>
                    <p class="text-sm text-emerald-700">Your email is verified. You have full access to your account.</p>
                </div>
            @endif
        </div>

        <!-- Status Message -->
        @if(session('status'))
            <div id="status-message"
                class="mb-6 p-4 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <!-- Rest of your dashboard content... -->
        <div class="bg-white rounded-3xl shadow-2xl shadow-indigo-100 border border-slate-100 overflow-hidden">
            <!-- Profile Header -->
            <div class="bg-gradient-to-r from-indigo-600 to-indigo-500 px-8 py-10">
                <div class="flex items-center space-x-6">
                    <div class="w-24 h-24 bg-white/20 backdrop-blur rounded-2xl flex items-center justify-center">
                        <span class="text-4xl font-bold text-white uppercase">
                            {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                        </span>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-white">Welcome back, {{ Auth::user()->name }}!</h1>
                        <p class="text-indigo-100 mt-1">{{ Auth::user()->email }}</p>
                        <div class="flex items-center space-x-2 mt-2">
                            <span class="px-2 py-0.5 bg-white/20 rounded-full text-xs text-white">ID:
                                {{ Auth::user()->id }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Sections -->
            <div class="p-8 space-y-8">
                <!-- User Information Section -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">User Information</h3>
                    </div>
                    <div class="bg-slate-900 rounded-2xl p-5 overflow-x-auto">
                        <pre class="text-emerald-400 font-mono text-xs">{{ json_encode([
    'id' => Auth::user()->id,
    'name' => Auth::user()->name,
    'email' => Auth::user()->email,
    'provider_name' => Auth::user()->provider_name ?? 'email',
    'provider_id' => Auth::user()->provider_id ?? 'Not set',
    'email_verified' => Auth::user()->hasVerifiedEmail(),
    'registered_at' => Auth::user()->created_at->format('F d, Y h:i A'),
    'created_at' => Auth::user()->created_at->toIso8601String(),
], JSON_PRETTY_PRINT) }}</pre>
                    </div>
                </div>

                <!-- Session Information Section -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Session Information</h3>
                    </div>
                    <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex justify-between items-center py-2 border-b border-slate-200">
                                <span class="text-sm text-slate-500">Session ID</span>
                                <span
                                    class="text-sm font-mono text-slate-700">{{ substr(session()->getId(), 0, 30) }}...</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-slate-200">
                                <span class="text-sm text-slate-500">Authenticated</span>
                                <span
                                    class="text-sm font-semibold text-emerald-600">{{ Auth::check() ? 'Yes' : 'No' }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-slate-200">
                                <span class="text-sm text-slate-500">Login Method</span>
                                <span class="text-sm font-semibold">
                                    @if(Auth::user()->provider_name == 'google')
                                        <span class="text-emerald-600">Google OAuth</span>
                                    @elseif(Auth::user()->provider_name)
                                        <span class="text-blue-600">{{ ucfirst(Auth::user()->provider_name) }} OAuth</span>
                                    @else
                                        <span class="text-slate-600">Email/Password</span>
                                    @endif
                                </span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-slate-200">
                                <span class="text-sm text-slate-500">Terms Accepted</span>
                                <span class="text-sm font-semibold">
                                    @if(Auth::user()->terms_accepted_at)
                                        <span
                                            class="text-emerald-600">{{ Auth::user()->terms_accepted_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-amber-600">Not yet</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Provider Information Section -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Authentication Provider
                            Details</h3>
                    </div>
                    <div class="bg-gradient-to-r from-indigo-50 to-slate-50 rounded-2xl p-6 border border-indigo-100">
                        @if(Auth::user()->provider_name == 'google')
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                    <svg class="w-7 h-7" viewBox="0 0 24 24">
                                        <path fill="#EA4335"
                                            d="M12.24 10.285V14.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.866-3.577-7.866-8s3.536-8 7.866-8c2.46 0 4.105 1.025 5.047 1.926l3.253-3.133C18.336 1.838 15.542 1 12.24 1 5.48 1 0 6.48 0 13s5.48 12 12.24 12c7.06 0 11.758-4.967 11.758-11.96 0-.806-.088-1.42-.194-1.755H12.24z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-800">Connected via Google Account</p>
                                    <p class="text-sm text-slate-500">Provider ID: <span
                                            class="font-mono">{{ Auth::user()->provider_id }}</span></p>
                                    <p class="text-xs text-emerald-600 mt-1">✓ OAuth2 Authentication</p>
                                </div>
                            </div>
                        @elseif(Auth::user()->provider_name)
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center shadow-sm">
                                    <span
                                        class="text-white font-bold text-lg">{{ ucfirst(substr(Auth::user()->provider_name, 0, 1)) }}</span>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-800">Connected via
                                        {{ ucfirst(Auth::user()->provider_name) }}</p>
                                    <p class="text-sm text-slate-500">Provider ID: <span
                                            class="font-mono">{{ Auth::user()->provider_id }}</span></p>
                                </div>
                            </div>
                        @else
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-slate-400 rounded-xl flex items-center justify-center shadow-sm">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-800">Local Account</p>
                                    <p class="text-sm text-slate-500">Registered with email and password</p>
                                    <p class="text-xs text-amber-600 mt-1">⚠ Not connected to any OAuth provider</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            // Hide status message
            const statusMessage = document.getElementById('status-message');
            if (statusMessage) {
                statusMessage.style.transition = 'opacity 0.5s ease';
                statusMessage.style.opacity = '0';
                setTimeout(() => {
                    if (statusMessage && statusMessage.parentNode) {
                        statusMessage.remove();
                    }
                }, 500);
            }

            // Hide verification alert
            const verificationAlert = document.getElementById('verification-alert');
            if (verificationAlert) {
                verificationAlert.style.transition = 'opacity 0.5s ease';
                verificationAlert.style.opacity = '0';
                setTimeout(() => {
                    if (verificationAlert && verificationAlert.parentNode) {
                        verificationAlert.remove();
                    }
                }, 500);
            }
        }, 5000);

        // Log user info to console
        console.group('Dashboard User Info');
        console.log('Name:', '{{ Auth::user()->name }}');
        console.log('Email:', '{{ Auth::user()->email }}');
        console.log('Provider:', '{{ Auth::user()->provider_name ?? "email" }}');
        console.log('Provider ID:', '{{ Auth::user()->provider_id ?? "N/A" }}');
        console.log('Email Verified:', '{{ Auth::user()->hasVerifiedEmail() ? "Yes" : "No" }}');
        console.log('Session ID:', '{{ session()->getId() }}');
        console.groupEnd();
    </script>
</body>

</html>

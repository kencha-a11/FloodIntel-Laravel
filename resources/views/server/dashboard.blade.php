<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-xl font-bold text-indigo-600">Socialite Auth Server</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">
                        Logged in via:
                        <strong class="uppercase text-indigo-600">
                            {{ Auth::user()->provider_name ?? 'Email' }}
                        </strong>
                    </span>

                    <form action="{{ route('server.logout') }}" method="POST">
                        @csrf
                        <button type="submit"
                            class="px-3 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 transition cursor-pointer">
                            Log Out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto mt-10 p-6">

        <div class="mb-6">
            @if (!Auth::user()->hasVerifiedEmail())
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg flex items-center justify-between">
                    <p class="text-sm text-yellow-800">
                        <strong>Warning:</strong> Your email address is not verified.
                    </p>
                    <form action="{{ route('verification.send') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-yellow-800 underline hover:text-yellow-900">
                            Resend Verification Email
                        </button>
                    </form>
                </div>
            @else
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg flex items-center space-x-2">
                    <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" />
                    </svg>
                    <p class="text-sm text-green-700">Your email is verified.</p>
                </div>
            @endif
        </div>

        @if(session('status'))
            <div class="mb-6 p-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <div class="p-6 bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center space-x-6 border-b border-gray-100 pb-6">
                <div
                    class="w-20 h-20 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-3xl font-bold uppercase ring-4 ring-indigo-50">
                    {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Welcome, {{ Auth::user()->name }}!
                    </h1>
                    <p class="text-gray-500">
                        {{ Auth::user()->email }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        User ID: {{ Auth::user()->id }}
                    </p>
                </div>
            </div>

            <div class="mt-6">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">
                    User Information
                </h3>
                <div class="bg-gray-900 p-4 rounded-lg overflow-x-auto">
                    <pre class="text-green-400 font-mono text-xs">{{ json_encode([
    'id' => Auth::user()->id,
    'name' => Auth::user()->name,
    'email' => Auth::user()->email,
    'provider_name' => Auth::user()->provider_name ?? 'email',
    'provider_id' => Auth::user()->provider_id ?? 'Not set',
    'provider_icon' => Auth::user()->provider_name == 'google' ? '🟢 Google Account' : (Auth::user()->provider_name ? '🔵 Social Account' : '📧 Email Account'),
    'registered_at' => Auth::user()->created_at->format('F d, Y h:i A'),
    'created_at' => Auth::user()->created_at->toIso8601String(),
], JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">
                    Session Information
                </h3>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-600">
                        <strong>Session ID:</strong> {{ session()->getId() }}<br>
                        <strong>Authenticated:</strong> {{ Auth::check() ? 'Yes' : 'No' }}<br>
                        <strong>Login Method:</strong>
                        @if(Auth::user()->provider_name == 'google')
                            <span class="text-green-600">Google OAuth</span>
                        @elseif(Auth::user()->provider_name)
                            <span class="text-blue-600">{{ ucfirst(Auth::user()->provider_name) }} OAuth</span>
                        @else
                            <span class="text-gray-600">Email/Password</span>
                        @endif
                    </p>
                </div>
            </div>

            <!-- Provider Info Card -->
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">
                    Authentication Provider Details
                </h3>
                <div class="bg-gradient-to-r from-indigo-50 to-blue-50 p-4 rounded-lg">
                    @if(Auth::user()->provider_name == 'google')
                        <div class="flex items-center space-x-3">
                            <svg class="w-8 h-8" viewBox="0 0 24 24">
                                <path fill="#EA4335"
                                    d="M12.24 10.285V14.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.866-3.577-7.866-8s3.536-8 7.866-8c2.46 0 4.105 1.025 5.047 1.926l3.253-3.133C18.336 1.838 15.542 1 12.24 1 5.48 1 0 6.48 0 13s5.48 12 12.24 12c7.06 0 11.758-4.967 11.758-11.96 0-.806-.088-1.42-.194-1.755H12.24z" />
                            </svg>
                            <div>
                                <p class="font-semibold text-gray-900">Connected via Google</p>
                                <p class="text-sm text-gray-600">Provider ID: {{ Auth::user()->provider_id }}</p>
                            </div>
                        </div>
                    @elseif(Auth::user()->provider_name)
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white font-bold">
                                {{ substr(Auth::user()->provider_name, 0, 1) }}
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">Connected via
                                    {{ ucfirst(Auth::user()->provider_name) }}
                                </p>
                                <p class="text-sm text-gray-600">Provider ID: {{ Auth::user()->provider_id }}</p>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center space-x-3">
                            <div
                                class="w-8 h-8 bg-gray-500 rounded-full flex items-center justify-center text-white font-bold">
                                E
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">Local Account</p>
                                <p class="text-sm text-gray-600">Registered with email and password</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </main>

    <script>
        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.mb-6.p-4');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Log provider info to console
        console.log('User Info:', {
            name: '{{ Auth::user()->name }}',
            email: '{{ Auth::user()->email }}',
            provider: '{{ Auth::user()->provider_name ?? "email" }}',
            provider_id: '{{ Auth::user()->provider_id ?? "N/A" }}'
        });
    </script>

</body>

</html>
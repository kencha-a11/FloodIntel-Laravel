<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Socialite Test</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-xl font-bold text-indigo-600">Supabase × Socialite</span>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">Logged in via:
                        <strong class="uppercase text-indigo-600">
                            {{ Auth::user()->provider_name ?? session('provider_name') ?? 'Google' }}
                        </strong>
                    </span>

<form action="{{ route('logout') }}" method="POST">
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

        @if(session('status'))
            <div class="mb-6 p-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <div class="p-6 bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center space-x-6 border-b border-gray-100 pb-6">
                <div
                    class="w-20 h-20 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-3xl font-bold uppercase ring-4 ring-indigo-50">
                    {{ substr(Auth::user()->name ?? session('user_name') ?? 'U', 0, 1) }}
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        {{ Auth::user()->name ?? session('user_name') }}
                    </h1>
                    <p class="text-gray-500">
                        {{ Auth::user()->email ?? session('user_email') }}
                    </p>
                </div>
            </div>

            @if(session('auth_token'))
                <div class="mt-6 p-4 bg-indigo-50/50 border border-indigo-100 rounded-xl">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-xs font-semibold text-indigo-900 uppercase tracking-wider">
                            Mobile Web Storage Token (Sanctum Key)
                        </h3>
                        <span
                            class="text-[10px] bg-indigo-600 text-white px-2 py-0.5 rounded-full font-medium">Active</span>
                    </div>
                    <div
                        class="p-3 bg-gray-900 rounded-lg font-mono text-xs text-green-400 break-all select-all select-text">
                        {{ session('auth_token') }}
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        This token has been securely set to your client browser engine's local storage storage vault.
                    </p>
                </div>

                <script>
                    (function () {
                        const secureToken = "{{ session('auth_token') }}";
                        if (secureToken) {
                            // Automatically cache inside mobile application web view container
                            localStorage.setItem('auth_token', secureToken);
                            console.log('Mobile architecture check: Auth token linked to LocalStorage.');
                        }
                    })();
                </script>
            @endif

            <div class="mt-6">
                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">
                    Supabase DB Record Payload
                </h3>
                <div class="bg-gray-900 p-4 rounded-lg overflow-x-auto">
                    <pre class="text-green-400 font-mono text-xs">
{
    "id": "{{ Auth::user()->id ?? 'N/A' }}",
    "name": "{{ Auth::user()->name ?? session('user_name') }}",
    "email": "{{ Auth::user()->email ?? session('user_email') }}",
    "provider_name": "{{ Auth::user()->provider_name ?? 'google' }}",
    "provider_id": "{{ Auth::user()->provider_id ?? 'Synced via OAuth' }}",
    "created_at": "{{ Auth::user()->created_at ?? now()->toIso8601String() }}"
}
                    </pre>
                </div>
            </div>
        </div>
    </main>

</body>

</html>

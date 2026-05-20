<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-gray-50 flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-white p-8 rounded-2xl shadow-xl border border-gray-100">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-extrabold text-gray-900">Welcome Back</h2>
            <p class="text-gray-500 text-sm mt-2">Manage your account securely</p>
        </div>

        {{-- Tabs --}}
        <div class="flex bg-gray-100 p-1 rounded-lg mb-6">
            <button onclick="showTab('login')" id="login-tab"
                class="flex-1 py-2 rounded-md font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600">Sign
                In</button>
            <button onclick="showTab('register')" id="register-tab"
                class="flex-1 py-2 rounded-md font-semibold text-sm transition-all text-gray-600">Register</button>
        </div>

        {{-- Error/Status Handling --}}
        @if ($errors->any() || session()->has('error') || session()->has('status'))
            <div class="mb-6 space-y-2">
                {{-- Session Error --}}
                @if (session()->has('error'))
                    <div class="p-3 text-sm text-red-700 bg-red-50 rounded-lg border border-red-200">
                        {{ session('error') }}
                    </div>
                @endif

                {{-- Status Message --}}
                @if (session()->has('status'))
                    <div class="p-3 text-sm text-emerald-700 bg-emerald-50 rounded-lg border border-emerald-200">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Validation Errors --}}
                @foreach ($errors->all() as $error)
                    <div class="p-3 text-sm text-red-700 bg-red-50 rounded-lg border border-red-200">
                        {{ $error }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Login Form --}}
        <form id="login-form" action="{{ route('server.login') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <label class="flex items-center text-sm text-gray-600">
                <input type="checkbox" name="remember" class="w-4 h-4 text-indigo-600 border-gray-300 rounded mr-2">
                Remember me
            </label>
            <button type="submit"
                class="w-full py-3 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 transition">Sign
                In</button>
        </form>

        {{-- Register Form --}}
        <form id="register-form" action="{{ route('server.register') }}" method="POST" class="space-y-4 hidden">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Full Name</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Confirm
                    Password</label>
                <input type="password" name="password_confirmation" required
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <button type="submit"
                class="w-full py-3 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 transition">Create
                Account</button>
        </form>

        <div class="relative py-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-gray-200"></div>
            </div>
            <div class="relative flex justify-center text-xs text-gray-400 uppercase bg-white px-2">Or continue with
            </div>
        </div>

        <a href="{{ route('server.google.redirect') }}"
            class="flex items-center justify-center w-full py-2.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
            <span class="text-sm font-medium text-gray-700">Sign in with Google</span>
        </a>
    </div>

    <script>
        function showTab(tab) {
            const forms = { login: document.getElementById('login-form'), register: document.getElementById('register-form') };
            const tabs = { login: document.getElementById('login-tab'), register: document.getElementById('register-tab') };

            Object.keys(forms).forEach(key => {
                forms[key].classList.toggle('hidden', key !== tab);
                tabs[key].className = (key === tab)
                    ? 'flex-1 py-2 rounded-md font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600'
                    : 'flex-1 py-2 rounded-md font-semibold text-sm transition-all text-gray-600';
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Auto-switch to register if validation errors found
            @if ($errors->has('name') || $errors->has('password_confirmation'))
                showTab('register');
            @endif
        });
    </script>
</body>

</html>

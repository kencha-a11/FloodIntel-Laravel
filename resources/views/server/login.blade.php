<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-white p-8 rounded-3xl shadow-2xl shadow-indigo-100 border border-slate-100">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-bold text-slate-900">Welcome Back</h2>
            <p class="text-slate-500 text-sm mt-2">Enter your details to access your account</p>
        </div>

        {{-- Tabs --}}
        <div class="grid grid-cols-2 bg-slate-100 p-1 rounded-2xl mb-8">
            <button onclick="showTab('login')" id="login-tab"
                class="py-2.5 rounded-xl font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600">Sign
                In</button>
            <button onclick="showTab('register')" id="register-tab"
                class="py-2.5 rounded-xl font-semibold text-sm transition-all text-slate-500 hover:text-slate-700">Register</button>
        </div>

        {{-- Error/Status Handling --}}
        @if ($errors->any() || session()->has('error') || session()->has('status'))
            <div class="mb-6 space-y-2">
                @if (session()->has('error'))
                    <div class="p-3 text-sm text-red-600 bg-red-50 rounded-xl border border-red-100">{{ session('error') }}
                    </div>
                @endif
                @if (session()->has('status'))
                    <div class="p-3 text-sm text-emerald-600 bg-emerald-50 rounded-xl border border-emerald-100">
                        {{ session('status') }}</div>
                @endif
                @foreach ($errors->all() as $error)
                    <div class="p-3 text-sm text-red-600 bg-red-50 rounded-xl border border-red-100">{{ $error }}</div>
                @endforeach
            </div>
        @endif

        {{-- Login Form --}}
        <form id="login-form" action="{{ route('server.login') }}" method="POST" class="space-y-5">
            @csrf
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>

            <div class="space-y-1">
                <div class="flex justify-between items-center">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Password</label>
                    <a href="{{ route('password.request') }}"
                        class="text-xs text-indigo-600 hover:text-indigo-700 font-semibold transition">Forgot
                        password?</a>
                </div>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>

            <label class="flex items-center text-sm text-slate-600">
                <input type="checkbox" name="remember"
                    class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 mr-2">
                Remember me
            </label>

            <button type="submit"
                class="w-full py-3.5 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 active:scale-[0.98] transition-all">Sign
                In</button>
        </form>

        {{-- Register Form --}}
        <form id="register-form" action="{{ route('server.register') }}" method="POST" class="space-y-5 hidden">
            @csrf
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Full Name</label>
                <input type="text" name="name" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Email</label>
                <input type="email" name="email" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Password</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Confirm Password</label>
                <input type="password" name="password_confirmation" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>
            <button type="submit"
                class="w-full py-3.5 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 active:scale-[0.98] transition-all">Create
                Account</button>
        </form>

        <div class="relative py-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-slate-200"></div>
            </div>
            <div class="relative flex justify-center text-xs text-slate-400 uppercase bg-white px-2">Or continue with
            </div>
        </div>

        <a href="{{ route('server.google.redirect') }}"
            class="flex items-center justify-center w-full py-3 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 active:scale-[0.98] transition-all font-medium text-slate-700">
            Sign in with Google
        </a>
    </div>

    <script>
        function showTab(tab) {
            const forms = { login: document.getElementById('login-form'), register: document.getElementById('register-form') };
            const tabs = { login: document.getElementById('login-tab'), register: document.getElementById('register-tab') };
            Object.keys(forms).forEach(key => {
                forms[key].classList.toggle('hidden', key !== tab);
                tabs[key].className = (key === tab) ? 'py-2.5 rounded-xl font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600' : 'py-2.5 rounded-xl font-semibold text-sm transition-all text-slate-500 hover:text-slate-700';
            });
        }
    </script>
</body>

</html>

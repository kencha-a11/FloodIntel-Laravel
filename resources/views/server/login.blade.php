<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-xl shadow-md">
        <h2 class="text-2xl font-bold text-center text-gray-800">Welcome Back</h2>

        <!-- Tabs for Login/Register -->
        <div class="flex border-b border-gray-200">
            <button onclick="showTab('login')" id="login-tab"
                class="flex-1 py-2 text-center font-medium text-indigo-600 border-b-2 border-indigo-600">
                Sign In
            </button>
            <button onclick="showTab('register')" id="register-tab"
                class="flex-1 py-2 text-center font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700">
                Create Account
            </button>
        </div>

        <!-- Error Messages -->
        @if(session('error'))
            <div class="p-3 text-sm text-red-700 bg-red-100 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="p-3 text-sm text-red-700 bg-red-100 rounded-lg">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('status'))
            <div class="p-3 text-sm text-green-700 bg-green-100 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <!-- Login Form -->
        <form id="login-form" action="{{ route('server.login') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition duration-150 font-medium">
                Sign In
            </button>
        </form>

        <!-- Register Form (Hidden by default) -->
        <form id="register-form" action="{{ route('server.register') }}" method="POST" class="space-y-4 hidden">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input type="password" name="password_confirmation" required
                    class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition duration-150 font-medium">
                Create Account
            </button>
        </form>

        <div class="relative flex py-5 items-center">
            <div class="flex-grow border-t border-gray-300"></div>
            <span class="flex-shrink mx-4 text-gray-400 text-sm">Or continue with</span>
            <div class="flex-grow border-t border-gray-300"></div>
        </div>

        <div class="space-y-3">
            <a href="{{ route('server.google.redirect') }}"
                class="flex items-center justify-center w-full px-4 py-2 space-x-2 text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition duration-150 cursor-pointer">
                <svg class="w-5 h-5" viewBox="0 0 24 24">
                    <path fill="#EA4335"
                        d="M12.24 10.285V14.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.866-3.577-7.866-8s3.536-8 7.866-8c2.46 0 4.105 1.025 5.047 1.926l3.253-3.133C18.336 1.838 15.542 1 12.24 1 5.48 1 0 6.48 0 13s5.48 12 12.24 12c7.06 0 11.758-4.967 11.758-11.96 0-.806-.088-1.42-.194-1.755H12.24z" />
                </svg>
                <span>Sign in with Google</span>
            </a>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function showTab(tab) {
            const loginForm = document.getElementById('login-form');
            const registerForm = document.getElementById('register-form');
            const loginTab = document.getElementById('login-tab');
            const registerTab = document.getElementById('register-tab');

            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                registerForm.classList.add('hidden');
                loginTab.classList.add('text-indigo-600', 'border-indigo-600');
                loginTab.classList.remove('text-gray-500', 'border-transparent');
                registerTab.classList.remove('text-indigo-600', 'border-indigo-600');
                registerTab.classList.add('text-gray-500', 'border-transparent');
            } else {
                loginForm.classList.add('hidden');
                registerForm.classList.remove('hidden');
                registerTab.classList.add('text-indigo-600', 'border-indigo-600');
                registerTab.classList.remove('text-gray-500', 'border-transparent');
                loginTab.classList.remove('text-indigo-600', 'border-indigo-600');
                loginTab.classList.add('text-gray-500', 'border-transparent');
            }
        }

        // Check URL hash for tab selection
        if (window.location.hash === '#register') {
            showTab('register');
        }

        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.p-3.text-sm');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>

    <style>
        .hidden {
            display: none;
        }
    </style>

</body>

</html>

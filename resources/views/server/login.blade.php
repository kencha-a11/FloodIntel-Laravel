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
                        {{ session('status') }}
                    </div>
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
                    <a href="{{ route('server.password.request') }}"
                        class="text-xs text-indigo-600 hover:text-indigo-700 font-semibold transition">Forgot password?</a>
                </div>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all outline-none">
            </div>

            {{-- Added Remember Me Checkbox --}}
            <div class="flex items-center">
                <input id="remember" type="checkbox" name="remember"
                    class="w-4 h-4 text-indigo-600 bg-slate-50 border-slate-200 rounded focus:ring-indigo-500/20 focus:ring-2 cursor-pointer">
                <label for="remember" class="ml-2 text-sm font-medium text-slate-600 cursor-pointer select-none">
                    Remember me
                </label>
            </div>

            <button type="submit"
                class="w-full py-3.5 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 active:scale-[0.98] transition-all">
                Sign In
            </button>
        </form>

        {{-- Register Form --}}
        <form id="register-form" action="{{ route('server.register') }}" method="POST" class="space-y-5 hidden"
            onsubmit="return validateTerms()">
            @csrf
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Full Name</label>
                <input type="text" name="name" value="{{ old('name') }}" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Password</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Confirm Password</label>
                <input type="password" name="password_confirmation" required
                    class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
            </div>

            {{-- Terms and Conditions Container --}}
            <div id="terms-container" class="flex items-start gap-2 text-sm text-slate-600">
                <input type="checkbox" name="terms" id="terms-checkbox" required
                    class="mt-1 w-4 h-4 text-indigo-600 border-slate-300 rounded opacity-50 cursor-not-allowed"
                    disabled>
                <span>I agree to the <button type="button" onclick="openTermsModal()"
                        class="text-indigo-600 font-semibold underline hover:text-indigo-800 transition">Terms and
                        Conditions</button></span>
            </div>

            <button type="submit" id="register-submit"
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
            class="flex items-center justify-center w-full py-3 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-all font-medium text-slate-700">Sign
            in with Google</a>
    </div>

    {{-- Terms Modal --}}
    <div id="terms-modal" class="fixed inset-0 bg-slate-900/50 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-3xl p-8 max-w-lg w-full shadow-2xl transform transition-all">
            <h3 class="text-2xl font-bold text-slate-800 mb-4">Terms and Conditions</h3>
            <div id="terms-content"
                class="h-64 overflow-y-auto border-y py-4 text-sm text-slate-600 space-y-4 mb-6 px-1">
                <p class="font-semibold text-indigo-600">Effective Date: May 20, 2026</p>
                <p>Welcome to our platform. By registering, you agree to comply with the following terms.</p>
                <p><strong>1. User Conduct</strong><br>You agree to use the service only for lawful purposes and respect
                    other users. Harassment, abuse, or fraudulent activity is strictly prohibited.</p>
                <p><strong>2. Privacy Policy</strong><br>We collect and process your data according to our Privacy
                    Policy. We will never share your personal information without consent, except as required by law.
                </p>
                <p><strong>3. Account Security</strong><br>You are responsible for maintaining the confidentiality of
                    your password and for all activities under your account.</p>
                <p><strong>4. Service Availability</strong><br>We reserve the right to modify or discontinue the service
                    at any time without notice.</p>
                <p><strong>5. Termination</strong><br>We may suspend or terminate your account if you violate these
                    terms.</p>
                <p><strong>6. Changes to Terms</strong><br>We may update these terms from time to time. Continued use of
                    the service constitutes acceptance of the new terms.</p>
                <p><strong>7. Data Collection</strong><br>We collect your name, email address, and login information for
                    account management purposes.</p>
                <p><strong>8. Third-Party Services</strong><br>We may use third-party services like Google OAuth for
                    authentication.</p>
                <p>By checking the box and creating an account, you acknowledge that you have read, understood, and
                    agree to be bound by these Terms and Conditions.</p>
                <p class="h-8"></p> <!-- Extra space to ensure scroll -->
            </div>
            <button id="agree-terms-btn" onclick="enableTermsCheckbox()" disabled
                class="w-full py-3 bg-indigo-300 text-white rounded-xl font-bold cursor-not-allowed transition-all">I
                have read and understood the terms</button>
        </div>
    </div>

    <script>
        // Tab switching
        function showTab(tab) {
            const loginForm = document.getElementById('login-form');
            const registerForm = document.getElementById('register-form');
            const loginTab = document.getElementById('login-tab');
            const registerTab = document.getElementById('register-tab');

            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                registerForm.classList.add('hidden');
                loginTab.className = 'py-2.5 rounded-xl font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600';
                registerTab.className = 'py-2.5 rounded-xl font-semibold text-sm transition-all text-slate-500 hover:text-slate-700';
            } else {
                loginForm.classList.add('hidden');
                registerForm.classList.remove('hidden');
                registerTab.className = 'py-2.5 rounded-xl font-semibold text-sm transition-all bg-white shadow-sm text-indigo-600';
                loginTab.className = 'py-2.5 rounded-xl font-semibold text-sm transition-all text-slate-500 hover:text-slate-700';
            }
        }

        // Open terms modal and reset button state
        function openTermsModal() {
            const modal = document.getElementById('terms-modal');
            const agreeBtn = document.getElementById('agree-terms-btn');
            const termsContent = document.getElementById('terms-content');

            // Reset button to disabled state
            agreeBtn.disabled = true;
            agreeBtn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700', 'cursor-pointer');
            agreeBtn.classList.add('bg-indigo-300', 'cursor-not-allowed');

            // Reset scroll position to top
            termsContent.scrollTop = 0;

            // Show modal
            modal.classList.remove('hidden');

            // Remove any existing scroll listener and add new one
            const handleScroll = function () {
                const scrollTop = termsContent.scrollTop;
                const scrollHeight = termsContent.scrollHeight;
                const clientHeight = termsContent.clientHeight;

                // Enable button when scrolled to bottom
                if (scrollTop + clientHeight >= scrollHeight - 5) {
                    agreeBtn.disabled = false;
                    agreeBtn.classList.remove('bg-indigo-300', 'cursor-not-allowed');
                    agreeBtn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'cursor-pointer');
                    // Remove listener once enabled
                    termsContent.removeEventListener('scroll', handleScroll);
                }
            };
            termsContent.addEventListener('scroll', handleScroll);
            handleScroll();
        }

        // Enable checkbox, auto-check, and lock it (prevent uncheck)
        function enableTermsCheckbox() {
            const checkbox = document.getElementById('terms-checkbox');

            // Remove disabled attribute
            checkbox.disabled = false;
            checkbox.classList.remove('opacity-50', 'cursor-not-allowed');

            // Auto-check
            checkbox.checked = true;

            // Add event listener to prevent unchecking
            checkbox.addEventListener('change', function preventUncheck(e) {
                if (!this.checked) {
                    this.checked = true;
                }
            });

            // Close modal
            document.getElementById('terms-modal').classList.add('hidden');
        }

        // Validate before form submission
        function validateTerms() {
            const checkbox = document.getElementById('terms-checkbox');
            if (!checkbox.checked) {
                alert('Please read and accept the Terms and Conditions first.');
                openTermsModal();
                return false;
            }
            return true;
        }

        // Persist tab selection on validation errors
        @if ($errors->has('name') || $errors->has('email') || $errors->has('password') || $errors->has('terms'))
            showTab('register');
        @endif

        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.mb-6 .p-3').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>

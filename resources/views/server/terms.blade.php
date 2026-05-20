<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Terms and Conditions - Socialite Server</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">

    <div class="max-w-3xl mx-auto bg-white p-8 rounded-3xl shadow-2xl shadow-indigo-100 border border-slate-100">

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <div class="w-16 h-16 bg-indigo-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
            </div>
            <h1 class="text-3xl font-bold text-slate-900">Terms and Conditions</h1>
            <p class="text-slate-500 text-sm mt-2">Effective Date: May 20, 2026</p>
            <div class="mt-4 p-3 bg-amber-50 rounded-xl text-amber-700 text-sm">
                Please read carefully before accepting. You must accept these terms to continue.
            </div>
        </div>

        <!-- Terms Content (scrollable) -->
        <div id="terms-content"
            class="h-96 overflow-y-auto border border-slate-200 rounded-xl p-6 space-y-6 text-sm text-slate-600 mb-6">
            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">1. Introduction</h2>
                <p>Welcome to Socialite Server. By accessing or using our service, you agree to be bound by these Terms
                    and Conditions. If you disagree with any part of these terms, you may not access the service.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">2. User Accounts</h2>
                <p>You are responsible for maintaining the confidentiality of your account credentials. You agree to
                    accept responsibility for all activities that occur under your account.</p>
                <ul class="list-disc list-inside mt-2 space-y-1 ml-4">
                    <li>You must be at least 13 years old to use this service</li>
                    <li>You agree to provide accurate and complete information</li>
                    <li>You are responsible for all activity under your account</li>
                    <li>Notify us immediately of any unauthorized use</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">3. User Conduct</h2>
                <p>You agree not to use the service for any unlawful purpose or in any way that could damage, disable,
                    or impair the service. Prohibited activities include:</p>
                <ul class="list-disc list-inside mt-2 space-y-1 ml-4">
                    <li>Harassing, abusing, or threatening others</li>
                    <li>Uploading malicious code or viruses</li>
                    <li>Attempting to gain unauthorized access to systems</li>
                    <li>Violating any applicable laws or regulations</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">4. Privacy and Data Collection</h2>
                <p>We collect and process your personal information as described in our Privacy Policy. By using this
                    service, you consent to such collection and processing. Information we collect includes:</p>
                <ul class="list-disc list-inside mt-2 space-y-1 ml-4">
                    <li>Name and email address</li>
                    <li>Login timestamps and IP addresses</li>
                    <li>Authentication provider information (if using Google OAuth)</li>
                    <li>Terms acceptance timestamp</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">5. Third-Party Services</h2>
                <p>Our service integrates with third-party authentication providers like Google. By using Google OAuth,
                    you agree to Google's Terms of Service and Privacy Policy. We are not responsible for the practices
                    of third parties.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">6. Termination</h2>
                <p>We reserve the right to suspend or terminate your account at our sole discretion, without notice, for
                    conduct that violates these terms or is harmful to other users or the service.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">7. Changes to Terms</h2>
                <p>We may modify these terms at any time. We will notify you of significant changes by posting a notice
                    on the service or sending you an email. Your continued use of the service constitutes acceptance of
                    the new terms.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">8. Limitation of Liability</h2>
                <p>To the fullest extent permitted by law, we shall not be liable for any indirect, incidental, special,
                    consequential, or punitive damages, or any loss of profits or revenues, whether incurred directly or
                    indirectly, or any loss of data, use, goodwill, or other intangible losses, resulting from your use
                    of the service.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">9. Governing Law</h2>
                <p>These terms shall be governed by and construed in accordance with the laws of your jurisdiction,
                    without regard to its conflict of law provisions.</p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-800 mb-3">10. Contact Information</h2>
                <p>If you have any questions about these Terms, please contact us at:
                    <a href="mailto:support@socialiteserver.com"
                        class="text-indigo-600 hover:underline">support@socialiteserver.com</a>
                </p>
            </section>

            <div class="bg-indigo-50 p-4 rounded-xl mt-4">
                <p class="font-semibold text-indigo-800">By accepting these terms, you acknowledge that:</p>
                <ul class="list-disc list-inside mt-2 space-y-1 text-indigo-700">
                    <li>You have read and understood these Terms and Conditions</li>
                    <li>You agree to comply with all provisions herein</li>
                    <li>You are legally capable of entering into this agreement</li>
                    <li>You consent to our data collection and processing practices</li>
                </ul>
            </div>
        </div>

        <!-- Scroll Requirement Notice -->
        <div id="scroll-notice" class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-700 text-sm">
            Please scroll to the bottom to read the complete terms before accepting.
        </div>

        <!-- Accept Button -->
        <form id="terms-form" action="{{ route('server.terms.accept') }}" method="POST"
            onsubmit="return validateAccept()">
            @csrf
            <button type="submit" id="accept-btn" disabled
                class="w-full py-3.5 bg-indigo-300 text-white rounded-xl font-bold cursor-not-allowed transition-all">
                I Have Read and Accept the Terms
            </button>
        </form>

        <!-- Logout Form -->
        <div class="mt-6 text-center">
            <form method="POST" action="{{ route('server.logout') }}" class="inline">
                @csrf
                <button type="submit" class="text-sm text-red-500 hover:text-red-700 transition">
                    Logout
                </button>
            </form>
        </div>
    </div>

    <script>
        const termsContent = document.getElementById('terms-content');
        const acceptBtn = document.getElementById('accept-btn');
        const scrollNotice = document.getElementById('scroll-notice');

        function checkScroll() {
            const scrollTop = termsContent.scrollTop;
            const scrollHeight = termsContent.scrollHeight;
            const clientHeight = termsContent.clientHeight;

            if (scrollTop + clientHeight >= scrollHeight - 5) {
                acceptBtn.disabled = false;
                acceptBtn.classList.remove('bg-indigo-300', 'cursor-not-allowed');
                acceptBtn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'cursor-pointer');
                scrollNotice.classList.remove('bg-amber-50', 'border-amber-200', 'text-amber-700');
                scrollNotice.classList.add('bg-emerald-50', 'border-emerald-200', 'text-emerald-700');
                scrollNotice.innerHTML = '✓ You have read the terms. You may now accept.';
                termsContent.removeEventListener('scroll', checkScroll);
            }
        }

        termsContent.addEventListener('scroll', checkScroll);
        checkScroll();

        function validateAccept() {
            if (acceptBtn.disabled) {
                alert('Please scroll to the bottom of the Terms and Conditions before accepting.');
                return false;
            }
            return true;
        }
    </script>
</body>

</html>

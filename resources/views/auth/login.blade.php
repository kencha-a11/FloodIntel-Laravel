<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Socialite Test</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md p-8 space-y-6 bg-white rounded-xl shadow-md">
        <h2 class="text-2xl font-bold text-center text-gray-800">Sign In</h2>

        @if(session('error'))
            <div class="p-3 text-sm text-red-700 bg-red-100 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        <form action="#" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700">Email Address</label>
                <input type="email" name="email" disabled
                    class="mt-1 block w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-gray-400 cursor-not-allowed"
                    placeholder="Disabled for Socialite test">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" disabled
                    class="mt-1 block w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-gray-400 cursor-not-allowed"
                    placeholder="••••••••">
            </div>
            <button type="button" disabled
                class="w-full py-2 px-4 bg-gray-400 text-white rounded-md cursor-not-allowed">
                Sign In with Credentials
            </button>
        </form>

        <div class="relative flex py-5 items-center">
            <div class="flex-grow border-t border-gray-300"></div>
            <span class="flex-shrink mx-4 text-gray-400 text-sm">Or continue with</span>
            <div class="flex-grow border-t border-gray-300"></div>
        </div>

        <div class="space-y-3">
            <button type="button" id="google-btn" onclick="handleGoogleLogin()"
                class="flex items-center justify-center w-full px-4 py-2 space-x-2 text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition duration-150 cursor-pointer">
                <svg id="google-icon" class="w-5 h-5" viewBox="0 0 24 24">
                    <path fill="#EA4335"
                        d="M12.24 10.285V14.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.866-3.577-7.866-8s3.536-8 7.866-8c2.46 0 4.105 1.025 5.047 1.926l3.253-3.133C18.336 1.838 15.542 1 12.24 1 5.48 1 0 6.48 0 13s5.48 12 12.24 12c7.06 0 11.758-4.967 11.758-11.96 0-.806-.088-1.42-.194-1.755H12.24z" />
                </svg>
                <span id="google-text">Sign in with Google</span>
            </button>
        </div>
    </div>

    <script>
        function handleGoogleLogin() {
            const btn = document.getElementById('google-btn');
            const text = document.getElementById('google-text');

            // Set Loading UI state
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            text.innerText = 'Connecting to Google...';

            // Query your API endpoint asynchronously
            fetch("{{ route('auth.v1.social.redirect', ['provider' => 'google']) }}")
                .then(response => {
                    if (!response.ok) throw new Error('Network response failure.');
                    return response.json();
                })
                .then(data => {
                    if (data.url) {
                        // Forward the mobile window frame onto the official Google Accounts panel
                        window.location.href = data.url;
                    } else {
                        throw new Error('No redirect URL returned.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Could not establish contact with the Auth Server.');

                    // Reset UI states if things break down
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    text.innerText = 'Sign in with Google';
                });
        }
    </script>

</body>

</html>

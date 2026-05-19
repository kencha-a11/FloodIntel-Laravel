<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - FloodIntel</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center h-screen">

    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Verify Your Email</h2>

        <p class="text-gray-600 mb-6">
            Salamat sa pag-register sa <strong>FloodIntel</strong>! Bago ka makapagsimula, pakiklik ang link na
            ipinadala namin sa iyong email address.
        </p>

        @if (session('message'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('message') }}
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-6">
            Hindi mo natanggap ang email?
        </p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded hover:bg-blue-700 transition duration-200">
                Resend Verification Email
            </button>
        </form>

        <div class="mt-6 text-center">
            <form method="POST" action="{{ route('server.logout') }}">
                @csrf
                <button type="submit" class="text-sm text-red-500 hover:underline">
                    Logout
                </button>
            </form>
        </div>
    </div>

</body>

</html>

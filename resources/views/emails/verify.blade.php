<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Verify Email Address</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #334155;
            margin: 0;
            padding: 40px 20px;
        }

        .container {
            max-width: 576px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .btn-container {
            text-align: center;
            margin: 32px 0;
        }

        .btn {
            background-color: #0f172a;
            color: #ffffff !important;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            display: inline-block;
            font-size: 16px;
        }

        .footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }

        .break-word {
            word-break: break-all;
            color: #2563eb;
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="header">
            <h2>FloodIntel</h2>
        </div>

        <p>Hello!</p>
        <p>Please click the button below to verify your email address and complete your registration.</p>

        <div class="btn-container">
            <a href="{{ $url }}" class="btn">Verify Email Address</a>
        </div>

        <p>If you did not create an account, no further action is required.</p>

        <p>Regards,<br>FloodIntel Team</p>

        <div class="footer">
            If you are having trouble clicking the "Verify Email Address" button, copy and paste the URL below into your
            web browser:
            <br><br>
            <span class="break-word">{{ $url }}</span>
        </div>
    </div>

</body>

</html>

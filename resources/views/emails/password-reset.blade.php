<!DOCTYPE html>
<html>

<head>
    <title>Reset Password - FloodIntel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f4f8; margin: 0; padding: 20px;">
    <div
        style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">

        <!-- Header -->
        <div style="text-align: center; margin-bottom: 30px;">
            <div
                style="background: linear-gradient(135deg, #4f46e5, #7c3aed); width: 60px; height: 60px; border-radius: 12px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
                <span style="color: white; font-size: 30px; font-weight: bold;">🌊</span>
            </div>
            <h1 style="color: #1e293b; margin: 0; font-size: 28px;">FloodIntel</h1>
            <p style="color: #64748b; margin-top: 8px;">Flood Monitoring & Alert System</p>
        </div>

        <!-- Greeting -->
        <h2 style="color: #334155; margin-bottom: 16px;">Kumusta, {{ $userName }}! 👋</h2>

        <p style="color: #475569; line-height: 1.6; margin-bottom: 24px;">
            Nakatanggap kami ng request para i-reset ang iyong password. I-click ang button sa ibaba para magpatuloy:
        </p>

        <!-- Button -->
        <div style="text-align: center; margin: 32px 0;">
            <a href="{{ $url }}" style="background: linear-gradient(135deg, #4f46e5, #7c3aed);
                      color: white;
                      padding: 14px 32px;
                      text-decoration: none;
                      border-radius: 12px;
                      font-weight: 600;
                      font-size: 16px;
                      display: inline-block;
                      box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                Reset Password
            </a>
        </div>

        <p style="color: #475569; line-height: 1.6; margin-bottom: 16px;">
            Kung hindi mo ginawa ang request na ito, huwag pansinin ang email na ito. Walang magbabago sa iyong account.
        </p>

        <!-- Alternative Link -->
        <div
            style="background-color: #f8fafc; padding: 16px; border-radius: 12px; margin: 24px 0; border-left: 4px solid #4f46e5;">
            <p style="color: #64748b; font-size: 13px; margin: 0 0 8px 0;">
                Kung hindi gumagana ang button, kopyahin at i-paste ang link na ito sa iyong browser:
            </p>
            <p style="color: #4f46e5; font-size: 12px; word-break: break-all; margin: 0; font-family: monospace;">
                {{ $url }}
            </p>
        </div>

        <!-- Footer -->
        <div style="margin-top: 40px; padding-top: 24px; border-top: 1px solid #e2e8f0; text-align: center;">
            <p style="color: #94a3b8; font-size: 12px; margin: 0;">
                &copy; {{ date('Y') }} FloodIntel. All rights reserved.
            </p>
        </div>
    </div>
</body>

</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'EmailSaaS Notification' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .header {
            background-color: #0f172a;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        .content {
            padding: 32px 24px;
        }
        .footer {
            background-color: #f1f5f9;
            color: #64748b;
            padding: 16px 24px;
            font-size: 12px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            margin: 20px 0;
        }
        .notice-box {
            background-color: #f8fafc;
            border-left: 4px solid #2563eb;
            padding: 12px 16px;
            margin: 16px 0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>EmailSaaS Platform</h1>
        </div>
        <div class="content">
            @yield('content')
        </div>
        <div class="footer">
            <p>This is an automated operational notification from EmailSaaS. Please do not reply directly to this message.</p>
            <p>&copy; {{ date('Y') }} EmailSaaS. All rights reserved.</p>
        </div>
    </div>
</body>
</html>

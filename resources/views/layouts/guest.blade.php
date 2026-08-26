<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Hệ thống OCR - Đại học Cửu Long') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/app-style.css') }}" rel="stylesheet">
    <style>
        *{box-sizing:border-box}
        body{
            font-family:'Inter',sans-serif;
            margin:0;
            min-height:100vh;
            display:flex;align-items:center;justify-content:center;
            background:#EFF7FF;
            padding:24px;
        }
        .auth-card{
            width:100%;max-width:420px;
            background:#ffffff;
            border:1px solid #D9E9F8;
            border-radius:18px;
            padding:32px 30px;
            box-shadow:0 8px 28px rgba(74,144,217,.12);
        }
        .auth-logo{display:flex;justify-content:center;margin-bottom:14px}
        .auth-eyebrow{
            display:block;text-align:center;
            font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
            color:#4A90D9;margin-bottom:6px;
        }
        .auth-school{
            display:block;text-align:center;
            font-size:13px;color:#5A7088;margin-bottom:18px;
        }
        .auth-title{font-size:22px;font-weight:700;color:#1A5490;margin-bottom:4px;text-align:center}
        .accent{color:#4A90D9}
        .auth-subtext{font-size:13.5px;color:#5A7088;text-align:center;margin-bottom:22px}
        .auth-card form label{
            display:block;font-size:13px;font-weight:600;color:#1C2B3A;
            margin:14px 0 6px;
        }
        .auth-card form input{
            width:100%;padding:10px 14px;border:1px solid #D9E9F8;border-radius:10px;
            font-size:14px;outline:none;transition:.15s;
        }
        .auth-card form input:focus{border-color:#4A90D9;box-shadow:0 0 0 3px rgba(74,144,217,.15)}
        .btn-auth{
            width:100%;margin-top:20px;padding:11px;border:none;border-radius:10px;
            background:#4A90D9;color:#fff;font-weight:700;font-size:14.5px;cursor:pointer;transition:.15s;
        }
        .btn-auth:hover{background:#2E6DA4}
        .auth-links{margin-top:18px;text-align:center;font-size:13.5px;color:#5A7088}
        .auth-links a{color:#4A90D9;font-weight:600;text-decoration:none}
        .auth-links a:hover{text-decoration:underline}
        .status-box{background:#E4F5EF;color:#1F6E52;padding:10px 14px;border-radius:10px;font-size:13.5px;margin-bottom:14px}
        .error-box{background:#FBEAE8;color:#A5352C;padding:10px 14px;border-radius:10px;font-size:13.5px;margin-bottom:14px}
        .error-box ul{margin:0;padding-left:18px}
    </style>
</head>
<body>

    <div class="auth-card">
        <div class="auth-logo">
            <x-university-logo :size="56" />
        </div>
        <span class="auth-eyebrow">Hệ thống OCR CCCD</span>
        <span class="auth-school">Trường Đại học Cửu Long</span>
        {{ $slot }}
    </div>

</body>
</html>
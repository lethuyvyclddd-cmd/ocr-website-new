<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/app-style.css') }}" rel="stylesheet">
</head>
<body>

    <div class="auth-card">
        <span class="auth-eyebrow">Hệ thống OCR CCCD</span>
        {{ $slot }}
    </div>

</body>
</html>
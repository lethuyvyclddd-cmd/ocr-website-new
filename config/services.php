<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'tesseract' => [
    'binary' => env('TESSERACT_BINARY', 'tesseract'),
],

    'poppler' => [
        // Mặc định 'pdftoppm' (đúng khi đã cài poppler-utils và có trong PATH,
        // ví dụ Linux server: apt install poppler-utils). Trên Windows dev,
        // set POPPLER_BINARY trong .env trỏ tới đường dẫn đầy đủ pdftoppm.exe.
        'binary' => env('POPPLER_BINARY', 'pdftoppm'),
    ],
    
    'ocr' => [
        'url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:8001'),
    ],

];
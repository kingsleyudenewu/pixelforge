<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'pixelforge' => [
        'url' => env('PIXELFORGE_URL', 'https://pixelforge.promptedit.dev/v1'),
        // Use one API key per environment so each environment's /v1/usage report covers only its own generations.
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
        // Outbound request budget shared by every caller; Pixelforge allows 60 per minute per account.
        'requests_per_minute' => (int) env('PIXELFORGE_REQUESTS_PER_MINUTE', 50),
        // Pixelforge allows 3 queued or processing generations per account at once.
        'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT_GENERATIONS', 3),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

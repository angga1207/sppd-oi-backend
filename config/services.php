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

    'semesta' => [
        'url' => env('SEMESTA_API_URL', 'https://semesta.oganilirkab.go.id/api'),
        'api_key' => env('SEMESTA_API_KEY', '!@#Op3nAp1K3584n9p0l'),
    ],

    'indonesia' => [
        'url' => env('INDONESIA_API_URL', 'https://ibnux.github.io/data-indonesia'),
    ],

    'firebase' => [
        'server_key' => env('FIREBASE_SERVER_KEY'),
        'sender_id' => env('FIREBASE_SENDER_ID', '716915912814'),
        'vapid_key' => env('FIREBASE_VAPID_KEY', 'BFVWywf66aXXIy5M25j1i_LCMgNsB-mYjIdYW7nT1kenCBEbwi9dtVwUG4EqNhaf2kX0K6E_fTZte1upQKGgZ_M'),
    ],

    'esign' => [
        'url' => env('ESIGN_SERVER_URL', 'http://103.162.35.72'),
        'user' => env('ESIGN_SERVER_USER', 'esign'),
        'pass' => env('ESIGN_SERVER_PASS', 'qwerty'),
        'link_qr' => env('ESIGN_LINK_QR', 'https://sppd.oganilirkab.go.id/'),
    ],

];

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'kavenegar' => [
        'key' => env('KAVENEGAR_API_KEY'),
        'otp_template' => env('KAVENEGAR_OTP_TEMPLATE', 'tukahr-login'),
        'approval_template' => env('KAVENEGAR_APPROVAL_TEMPLATE', 'tukahr-approval'),
        // Optional dedicated line; when set, invite links are sent by SMS.
        'sender' => env('KAVENEGAR_SENDER'),
        'timeout' => (int) env('KAVENEGAR_TIMEOUT_SECONDS', 8),
    ],

];

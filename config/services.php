<?php

declare(strict_types=1);

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'dialog_sms' => [
        'url' => env('DIALOG_SMS_URL', 'https://esms.dialog.lk'),
        'send_url' => env('DIALOG_SMS_SEND_URL', 'https://e-sms.dialog.lk/api/v2/sms'),
        'username' => env('DIALOG_SMS_USERNAME'),
        'password' => env('DIALOG_SMS_PASSWORD'),
        'mask' => env('DIALOG_SMS_MASK'),

        // Testing against real records: comma-separated handsets that receive every SMS instead
        // of the customer (App\Services\Sms\AllowListedSmsService). Ignored in production.
        'allowed_numbers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SMS_ALLOWED_NUMBERS', ''))
        ))),
    ],

    // CDP Connect: the CDP investment system, read for Customer 360 and customer search.
    'cdp_connect' => [
        'base_url' => env('CDP_CONNECT_BASE_URL'),
        'api_key' => env('CDP_CONNECT_API_KEY'),
        'timeout' => (int) env('CDP_CONNECT_TIMEOUT', 15),
        'key_header' => 'X-Credix-Key',
    ],

    // The other direction: CDP Core calling CrediX (policy-hold lookup before a payout). Core sends this key in X-Core-Key.
    'cdp_core' => [
        'inbound_key' => env('CDP_CORE_INBOUND_KEY'),
    ],

    // Developer machines only (APP_ENV=local): write OTP codes to the log instead of sending SMS.
    // Ignored in every other environment. Keep false unless you are testing OTP flows locally.
    'otp' => [
        'log_codes_locally' => env('OTP_LOG_CODES_LOCALLY', false),
    ],

];

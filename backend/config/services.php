<?php

return [

    'internal_api_key' => env('INTERNAL_API_KEY', ''),

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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'waha' => [
        'driver' => env('WAHA_DRIVER', 'official'),
        'base_url' => env('WAHA_BASE_URL', 'http://127.0.0.1:8010'),
        'api_key' => env('WAHA_API_KEY', 'your_super_secret_api_key_here'),
        'session' => env('WAHA_SESSION', 'default'),
        'force_default_session' => env('WAHA_FORCE_DEFAULT_SESSION', true),
        'send_message_endpoint' => env('WAHA_SEND_MESSAGE_ENDPOINT', '/api/sendText'),
        'check_number_endpoint' => env('WAHA_CHECK_NUMBER_ENDPOINT', '/api/contacts/check-exists'),
        'status_endpoint' => env('WAHA_STATUS_ENDPOINT', '/api/sessions'),
        'timeout_seconds' => env('WAHA_TIMEOUT_SECONDS', 30),
        'enabled' => env('WAHA_ENABLED', true),
        'webhook_url' => env('WAHA_WEBHOOK_URL', 'http://bothwa:8020/webhook/waha'),
        'webhook_events' => env('WAHA_WEBHOOK_EVENTS', 'message'),
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET', ''),
        'webhook_auto_configure' => env('WAHA_WEBHOOK_AUTO_CONFIGURE', true),
    ],

];

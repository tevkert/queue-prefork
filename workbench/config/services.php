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

    // Socialite providers; dummy credentials, never called by the soak.
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID', 'workbench'),
        'client_secret' => env('DISCORD_CLIENT_SECRET', 'workbench'),
        'redirect' => env('APP_URL').'/auth/discord/callback',
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID', 'workbench'),
        'client_secret' => env('TWITCH_CLIENT_SECRET', 'workbench'),
        'redirect' => env('APP_URL').'/auth/twitch/callback',
    ],

    // Web push (minishlink/web-push). Test VAPID keys; subscriptions point at the stub.
    'webpush' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:push@workbench.test'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

];

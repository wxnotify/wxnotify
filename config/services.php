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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apprise' => [
        'base_url' => env('APPRISE_API_URL', 'http://apprise:8000'),
        'from_email' => env('APPRISE_FROM_EMAIL', 'alerts@wxnotify.test'),
    ],

    'chatwoot' => [
        'base_url' => env('CHATWOOT_BASE_URL'),
        'website_token' => env('CHATWOOT_WEBSITE_TOKEN'),
        'identity_secret' => env('CHATWOOT_IDENTITY_SECRET'),
    ],

    'pipeline' => [
        'webhook_token' => env('PIPELINE_WEBHOOK_TOKEN'),
        'products_path' => env('PIPELINE_PRODUCTS_PATH', '/products'),
    ],

    'docker' => [
        'socket_path' => env('DOCKER_SOCKET_PATH', '/var/run/docker.sock'),
    ],

];

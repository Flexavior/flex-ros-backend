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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ConvyMes — multi-channel messaging microservice
    |--------------------------------------------------------------------------
    |
    | ConvyMes is the Node/Express omnichannel gateway that talks to Facebook
    | Page Messenger, Viber and LINE. MSS-CRM mirrors its conversations into a
    | single pane and replies back through it. These values are the fallback
    | defaults; admins can override them at runtime via System Settings
    | (keys: integrations.convymes.*).
    |
    */

    'convymes' => [
        'enabled' => env('CONVYMES_ENABLED', false),
        'base_url' => env('CONVYMES_BASE_URL', 'http://localhost:3000'),
        'email' => env('CONVYMES_EMAIL', 'admin@convymes.local'),
        'password' => env('CONVYMES_PASSWORD', 'admin123'),
        'webhook_secret' => env('CONVYMES_WEBHOOK_SECRET', ''),
    ],

    'microsoft' => [
        'tenant_id' => env('AZURE_TENANT_ID'),
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'teams_webhook_url' => env('MICROSOFT_TEAMS_WEBHOOK_URL'),
    ],

    'sso' => [
        'allowed_email_domains' => env('SSO_ALLOWED_EMAIL_DOMAINS', ''),
        'microsoft' => [
            'enabled' => env('SSO_MICROSOFT_ENABLED', false),
            'tenant_id' => env('SSO_MICROSOFT_TENANT_ID', env('AZURE_TENANT_ID')),
            'client_id' => env('SSO_MICROSOFT_CLIENT_ID', env('AZURE_CLIENT_ID')),
            'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET', env('AZURE_CLIENT_SECRET')),
            'redirect_uri' => env('SSO_MICROSOFT_REDIRECT_URI', env('APP_URL').'/api/v1/auth/sso/microsoft/callback'),
            'authority' => env('AZURE_AUTHORITY', 'https://login.microsoftonline.com'),
        ],
        'google' => [
            'enabled' => env('SSO_GOOGLE_ENABLED', false),
            'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
            'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('SSO_GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/sso/google/callback'),
        ],
    ],

];

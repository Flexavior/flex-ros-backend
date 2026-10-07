<?php

$origins = array_values(array_unique(array_filter(array_merge(
    array_filter([env('FRONTEND_URL')]),
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
))));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | When the React SPA is on a different origin than the API (e.g. Render
    | static site + Docker API), set FRONTEND_URL (and optional CORS_ALLOWED_ORIGINS).
    | If no origins are configured, defaults to '*' (local dev friendly).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins !== [] ? $origins : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

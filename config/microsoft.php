<?php

return [

    'tenant_id' => env('AZURE_TENANT_ID'),
    'client_id' => env('AZURE_CLIENT_ID'),
    'client_secret' => env('AZURE_CLIENT_SECRET'),
    'redirect_uri' => env('AZURE_REDIRECT_URI', env('APP_URL').'/api/v1/integrations/microsoft/callback'),

    'authority' => env('AZURE_AUTHORITY', 'https://login.microsoftonline.com'),

    'graph_base' => 'https://graph.microsoft.com/v1.0',

    /*
    | Delegated scopes — Business Basic mailboxes via Graph.
    */
    'scopes' => [
        'openid',
        'profile',
        'offline_access',
        'User.Read',
        'Mail.Send',
        'Mail.Read',
    ],

    'teams_webhook_url' => env('MICROSOFT_TEAMS_WEBHOOK_URL'),

    'webhook_client_state' => env('MICROSOFT_GRAPH_CLIENT_STATE', 'mss-crm-graph'),

];

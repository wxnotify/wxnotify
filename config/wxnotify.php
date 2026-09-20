<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment Mode
    |--------------------------------------------------------------------------
    |
    | Distinguishes the hosted cloud product from a self-hosted instance.
    | Cloud-only concerns (billing, the Chatwoot support widget, cross-tenant
    | staff tooling) are conditionally registered based on this value.
    |
    */

    'mode' => env('WXNOTIFY_MODE', 'cloud'),

    'webhooks' => [
        // Honored only when mode === 'selfhost': lets a self-hosted instance
        // deliver to services on its own private network.
        'allow_private_networks' => env('WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE', false),
    ],

];

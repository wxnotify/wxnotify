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

    // Optional dedicated host for the public View Product pages (e.g. a
    // subdomain behind Cloudflare caching). Unset serves them from the main
    // domain, which is what local development and self-hosting use.
    'product_domain' => env('WXNOTIFY_PRODUCT_DOMAIN'),

    'webhooks' => [
        // Honored only when mode === 'selfhost': lets a self-hosted instance
        // deliver to services on its own private network.
        'allow_private_networks' => env('WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE', false),
    ],

    'pipeline' => [
        'delivery_retention_hours' => (int) env('PIPELINE_DELIVERY_RETENTION_HOURS', 48),
        'stale_minutes' => [
            'nwws' => (int) env('PIPELINE_STALE_MINUTES_NWWS', 15),
            'emwin' => (int) env('PIPELINE_STALE_MINUTES_EMWIN', 15),
            'emwin_http' => (int) env('PIPELINE_STALE_MINUTES_EMWIN_HTTP', 30),
        ],
    ],

];

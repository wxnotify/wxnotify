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

    /*
    |--------------------------------------------------------------------------
    | Client IP behind a proxy
    |--------------------------------------------------------------------------
    |
    | Both are off by default: the client is the connecting address and every
    | forwarding header is ignored, since any client can send one. See
    | App\Support\ClientIp.
    |
    | trusted_proxies  `*`, or a comma list of load balancer / CDN IPs or CIDRs
    |                  whose X-Forwarded-For is believed (application-wide).
    | header           A header to prefer over that, e.g. CF-Connecting-IP for
    |                  Cloudflare. Only believed from a trusted proxy.
    |
    */
    'client_ip' => [
        'trusted_proxies' => env('WXNOTIFY_TRUSTED_PROXIES'),
        'header' => env('WXNOTIFY_CLIENT_IP_HEADER'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Mobile app sign-in
    |--------------------------------------------------------------------------
    |
    | callback_uri / denied_uri  The deep links the browser handoff returns to;
    |                            the code and state are appended as path segments.
    | code_ttl_seconds           How long a single-use authorization code lives.
    | token_days                 Sliding lifetime of an app token.
    |
    */
    'app_auth' => [
        'callback_uri' => 'wxnotify://auth/callback',
        'denied_uri' => 'wxnotify://auth/denied',
        'code_ttl_seconds' => 120,
        'token_days' => 90,
    ],

    'map' => [
        'warning_radius_miles' => 150,
        'max_warnings' => 100,
        'simplify_degrees' => 0.01,
        'basemaps' => [
            ['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16],
            ['key' => 'imagery', 'label' => 'Imagery', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16],
        ],
        'radar' => [
            'wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows',
            'layer' => 'conus_bref_qcd',
        ],
        'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
    ],

];

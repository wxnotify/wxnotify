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

    /*
    |--------------------------------------------------------------------------
    | SPC Day 1 outlook pairing
    |--------------------------------------------------------------------------
    |
    | SPC sends the Day 1 outlook as two bulletins 0-32 seconds apart: the
    | text (SWODY1) and its areal outline (PTSDY1). A text that arrives after
    | its outline takes the outline's risk areas at once (one alert, every
    | rule); this delay only bounds the fallback for an orphan outline: one
    | whose text has not arrived after this many seconds is sent on its own.
    | 0 sends an unpaired outline on its own at once, as before (the outlook
    | may then go out twice). The wait needs a real queue driver: when the
    | default queue connection uses `sync`, this setting is ignored and the
    | delay is 0 (no wait, no merge into a later text), so a self-hosted
    | instance on sync keeps only the old duplicate for Nationwide rules.
    | See App\Actions\Pipeline\BackfillOutlookRiskAreas.
    |
    */
    'outlook_pairing_delay_seconds' => (int) env('WXNOTIFY_OUTLOOK_PAIRING_DELAY_SECONDS', 60),

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

    /*
    | The app link a push for a stored bulletin carries, so tapping it opens that bulletin's View Product
    | page in the app. Must equal the scheme and host the app registers (mobile/routes/mobile.php: /product/{link});
    | the bulletin's public page address follows as one base64url path segment.
    */
    'app_product_uri' => 'wxnotify://product',

    /*
    | The app link a push with no product page carries for an app build that declared `tap_ref` at registration:
    | the alert group reference (App\Support\AlertRef) follows as one path segment, so the tap opens Alerts and
    | marks that alert read. Must equal the app's registered scheme and route (mobile/routes/mobile.php: /alert/{ref}).
    */
    'app_alert_uri' => 'wxnotify://alert',

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

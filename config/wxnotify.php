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

];

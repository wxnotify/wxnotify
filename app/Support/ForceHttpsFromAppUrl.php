<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Makes every generated URL (url(), asset(), route(), Livewire's update URL, Filament's assets)
 * https when APP_URL is https. Behind a TLS proxy the app sees plain http unless the proxy is
 * trusted (WXNOTIFY_TRUSTED_PROXIES), and an https page with http asset URLs is blocked as mixed
 * content. An http APP_URL leaves the scheme to the request. Runs at boot; reads config only.
 */
class ForceHttpsFromAppUrl
{
    public function __invoke(): void
    {
        if (Str::startsWith(strtolower((string) config('app.url')), 'https://')) {
            URL::forceScheme('https');
        }
    }
}

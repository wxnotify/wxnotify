<?php

namespace App\Support\Apprise;

use Illuminate\Support\Facades\Http;

class AppriseApiClient
{
    /**
     * Send a message through the Apprise API's stateless /notify/ endpoint.
     *
     * Returns true only when Apprise actually delivered the notification —
     * a malformed URL is reported as a "success" (204, no body) by the
     * Apprise API itself, so a plain successful()-check on the HTTP
     * response isn't enough; see docs/phase-0-filament-blueprint.md for
     * how this was discovered (mailto:// silently dropped without an
     * explicit `mode=insecure` + `from=` against an unencrypted relay).
     */
    public function notify(string $url, string $body, ?string $title = null): bool
    {
        $response = Http::baseUrl(config('services.apprise.base_url'))
            ->post('/notify/', array_filter([
                'urls' => $url,
                'body' => $body,
                'title' => $title,
            ]));

        return $response->status() === 200;
    }
}

<?php

namespace App\Support\Delivery;

use GuzzleHttp\Psr7\Response;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * Spatie's CallWebhookJob passes no 'allow_redirects' option, so Guzzle applies its
 * default (follow up to 5 redirects, over http OR https). A subscriber-supplied
 * endpoint could answer 302 Location: http://169.254.169.254/… and reach exactly the
 * private address WebhookUrlGuard just cleared the original URL of — no DNS race
 * needed, and the http-only hop bypasses the https-only rule too. Every webhook this
 * app sends goes through this subclass instead (config/webhook-server.php's
 * 'webhook_job'), which turns redirect following off.
 */
class NoRedirectCallWebhookJob extends CallWebhookJob
{
    /**
     * @param  array<string, mixed>  $body
     */
    protected function createRequest(array $body): Response
    {
        // The parent array_merges $body into the Guzzle request options, so an extra
        // option smuggled in here lands alongside timeout/verify/headers.
        return parent::createRequest([...$body, 'allow_redirects' => false]);
    }
}

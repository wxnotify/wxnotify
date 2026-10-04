<?php

namespace App\Support\Delivery;

use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Spatie\WebhookServer\CallWebhookJob;

/**
 * Spatie's CallWebhookJob passes no 'allow_redirects' option, so Guzzle applies its
 * default (follow up to 5 redirects, over http OR https). A subscriber-supplied
 * endpoint could answer 302 Location: http://169.254.169.254/… and reach exactly the
 * private address WebhookUrlGuard just cleared the original URL of — no DNS race
 * needed, and the http-only hop bypasses the https-only rule too. Every webhook this
 * app sends goes through this subclass instead (config/webhook-server.php's
 * 'webhook_job'), which turns redirect following off.
 *
 * It also pins the connection. The guard's DNS lookup and curl's own are separate, so
 * a hostile DNS server could answer the guard with a public address and curl with a
 * private one. The job asks WebhookUrlGuard for a pin immediately before the request,
 * which looks the host up once and hands curl exactly the addresses it vetted (for a
 * proxied call, the proxy host: the proxy resolves the target). A host the guard no
 * longer clears at that moment fails the call without sending anything.
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
        $guard = app(WebhookUrlGuard::class);

        $pin = match (true) {
            $this->proxy === null => $guard->requestOptions((string) $this->webhookUrl),
            is_string($this->proxy) => $guard->proxyRequestOptions($this->proxy),
            default => null,
        };

        if ($pin === null) {
            throw new RuntimeException('Webhook call refused');
        }

        return parent::createRequest([...$body, ...$pin, 'allow_redirects' => false]);
    }
}

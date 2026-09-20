<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\WebhookUrlGuard;
use Illuminate\Support\Str;
use Spatie\WebhookServer\WebhookCall;
use Throwable;

/**
 * spatie/laravel-webhook-server 3.11 spike: dispatchSync() + throwExceptionOnFailure()
 * rethrows Guzzle's ServerException/ConnectException synchronously (no failed_jobs row);
 * CallWebhookJob::getClient() resolves app(GuzzleHttp\Client::class) — the test seam;
 * DefaultSigner sets header "Signature" = hash_hmac('sha256', json_encode($payload), $secret),
 * and the body is exactly that same json_encode($payload), so the raw body verifies.
 */
class WebhookTransport implements Transport
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $url = $channel->config['url'] ?? null;
        $secret = $channel->config['secret'] ?? null;

        if (! is_string($url) || ! is_string($secret) || $url === '' || $secret === '') {
            return DeliveryResult::failed('This webhook channel is missing its URL or signing secret.');
        }

        // Re-checked on every send: DNS can change after the URL was saved.
        if (($violation = $this->guard->violation($url)) !== null) {
            return DeliveryResult::failed($violation);
        }

        $payload = [
            'type' => $meta['type'] ?? 'alert',
            'title' => $title,
            'body' => $body,
            'sent_at' => now()->utc()->toIso8601String(),
        ] + array_diff_key($meta, ['type' => true]);

        try {
            WebhookCall::create()
                ->url($url)
                ->payload($payload)
                ->useSecret($secret)
                ->timeoutInSeconds(10)
                ->maximumTries(1)
                ->throwExceptionOnFailure()
                ->dispatchSync();
        } catch (Throwable $e) {
            // Guzzle's message quotes the endpoint's response body, which could echo the
            // secret back: redact before truncating, so a straddling match can't survive.
            return DeliveryResult::failed(
                'Webhook delivery failed: '.Str::limit(str_replace($secret, '[redacted]', $e->getMessage()), 200)
            );
        }

        return DeliveryResult::delivered();
    }
}

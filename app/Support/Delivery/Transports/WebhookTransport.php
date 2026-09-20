<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
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
            return DeliveryResult::failed($this->failureMessage($e));
        }

        return DeliveryResult::delivered();
    }

    /**
     * Built from the exception's type and HTTP status, never from its message.
     *
     * Guzzle renders the full request URI (which routinely carries the subscriber's
     * only credential in its query string) and ~120 characters of the endpoint's
     * response body into every message, and this string ends up in
     * delivery_logs.error_detail, which staff can read. Redacting known substrings
     * would only catch the spellings we thought of; composing the message ourselves
     * cannot leak anything at all.
     */
    private function failureMessage(Throwable $e): string
    {
        if ($e instanceof BadResponseException) {
            return 'Webhook delivery failed: the endpoint returned HTTP '.$e->getResponse()->getStatusCode().'.';
        }

        if ($e instanceof ConnectException) {
            return 'Webhook delivery failed: the endpoint could not be reached.';
        }

        // Spatie raises a plain exception for any other non-2xx it sees itself —
        // notably a 3xx, which this app refuses to follow.
        return 'Webhook delivery failed: the endpoint did not accept the message.';
    }
}

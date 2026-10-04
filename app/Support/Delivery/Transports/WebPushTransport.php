<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * The push endpoint is a subscriber-controlled capability URL, so no failure text is ever
 * derived from the report reason, an exception or a response body (all can echo the endpoint):
 * errors are fixed strings plus the numeric HTTP status only.
 */
class WebPushTransport implements Transport
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $config = $channel->config ?? [];

        if (blank($config['endpoint'] ?? null) || blank($config['public_key'] ?? null) || blank($config['auth_token'] ?? null)) {
            return DeliveryResult::failed('This Web Push channel is missing its browser subscription.');
        }

        if (blank(config('webpush.vapid.public_key')) || blank(config('webpush.vapid.private_key'))) {
            return DeliveryResult::failed('Web Push is not configured on this server (missing VAPID keys).');
        }

        // Re-checked on every send, same as webhooks. The pin makes the connection go
        // to exactly the addresses the guard vetted, not to a fresh DNS answer.
        $pin = $this->guard->requestOptions($config['endpoint']);

        if ($pin === null) {
            return DeliveryResult::failed($this->guard->violation($config['endpoint']) ?? 'That host name could not be resolved.');
        }

        try {
            $webPush = app(WebPush::class, [
                'auth' => ['VAPID' => [
                    'subject' => config('webpush.vapid.subject') ?: url('/'),
                    'publicKey' => config('webpush.vapid.public_key'),
                    'privateKey' => config('webpush.vapid.private_key'),
                ]],
                // Never follow redirects: a 3xx from the subscriber-chosen endpoint could steer this
                // request to an address the guard would have refused.
                'client' => new Client(['allow_redirects' => false, 'timeout' => 10, 'connect_timeout' => 5, ...$pin]),
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $config['endpoint'],
                    'publicKey' => $config['public_key'],
                    'authToken' => $config['auth_token'],
                    'contentEncoding' => $config['content_encoding'] ?? 'aes128gcm',
                ]),
                json_encode([
                    'type' => $meta['type'] ?? 'alert',
                    'title' => $title ?? 'WxNotify',
                    'body' => Str::limit($body, 500, preserveWords: true),
                    'url' => $meta['url'] ?? null,
                ]),
                ['TTL' => 3600, 'urgency' => 'high'],
            );
        } catch (Throwable) {
            return DeliveryResult::failed('The push message could not be prepared or sent.');
        }

        $status = $report->getResponse()?->getStatusCode();

        // The library treats a 3xx as success; with redirects disabled it is a failure here.
        if ($report->isSuccess() && $status !== null && $status >= 200 && $status < 300) {
            return DeliveryResult::delivered();
        }

        if ($report->isSubscriptionExpired()) {
            // The browser revoked the subscription: the channel can no longer deliver until re-enabled.
            $channel->forceFill(['verified_at' => null])->save();

            return DeliveryResult::failed('This browser subscription is no longer valid; re-enable Web Push from Notification Channels.');
        }

        return DeliveryResult::failed($status === null
            ? 'The push service could not be reached.'
            : "Push service rejected the message (HTTP {$status}).");
    }
}

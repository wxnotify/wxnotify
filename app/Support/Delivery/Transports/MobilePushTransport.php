<?php

namespace App\Support\Delivery\Transports;

use App\Actions\Alerts\PushBadgeCount;
use App\Jobs\PruneMobilePushChannel;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\FcmClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes one alert to one signed-in phone. Failure text comes from FcmClient (fixed strings plus a
 * status), never from a response body, an exception or the device token.
 */
class MobilePushTransport implements Transport
{
    /** Long enough for DeliverWeatherAlert to write its delivery-log row first. */
    public const PRUNE_DELAY_SECONDS = 60;

    /**
     * Named in the FCM data of every phone push for app builds from before read/unread alerts, whose listener
     * cleared the badge after a foreground push. Current builds have no such class (the firebase plugin just
     * logs that it does not exist). Harmless; remove it and the payload below in a later release.
     */
    public const FOREGROUND_PUSH_EVENT = 'App\\Events\\ForegroundPushReceived';

    /** No constructor arguments: without an explicit payload the plugin would pass `url` and `type` by name. */
    public const FOREGROUND_PUSH_PAYLOAD = '{}';

    public function __construct(private readonly FcmClient $fcm) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $token = $channel->config['fcm_token'] ?? null;

        if (blank($token)) {
            return DeliveryResult::failed('This device channel has no push token; open the app to register it again.');
        }

        $badge = ($channel->config['platform'] ?? null) === 'ios' ? $this->badge($channel, $meta) : null;

        $result = $this->fcm->send(
            $token,
            $title ?? 'WxNotify',
            Str::limit($body, 200, preserveWords: true),
            [
                'url' => $this->tapTarget($meta),
                'type' => (string) ($meta['type'] ?? 'alert'),
                'event' => self::FOREGROUND_PUSH_EVENT,
                'payload' => self::FOREGROUND_PUSH_PAYLOAD,
            ],
            $badge,
        );

        if ($result->success) {
            return DeliveryResult::delivered();
        }

        if ($result->tokenInvalid) {
            PruneMobilePushChannel::dispatch($channel->id, sha1($token))->delay(self::PRUNE_DELAY_SECONDS);
        }

        return DeliveryResult::failed((string) $result->error);
    }

    /**
     * The iOS icon number: the subscriber's unread alerts including this one (PushBadgeCount). The jobs put the
     * alert's group (`alert_rule_id`, `ingested_product_id`, `batch_key`) in the meta; only `type` 'alert' counts as an alert,
     * so the test push carries the plain unread count. The number is a courtesy the app corrects from the feed's
     * `unread_count`, so a failing count (a query error, or code deployed before the `read_at` migration) sends
     * the push without a badge instead of losing the alert; only the exception class is logged.
     *
     * @param  array<string, mixed>  $meta
     */
    private function badge(NotificationChannel $channel, array $meta): ?int
    {
        $alertRuleId = $meta['alert_rule_id'] ?? null;
        $ingestedProductId = $meta['ingested_product_id'] ?? null;
        $batchKey = $meta['batch_key'] ?? null;

        try {
            return app(PushBadgeCount::class)(
                $channel->user,
                is_int($alertRuleId) ? $alertRuleId : null,
                is_int($ingestedProductId) ? $ingestedProductId : null,
                ($meta['type'] ?? 'alert') === 'alert',
                is_string($batchKey) ? $batchKey : null,
            );
        } catch (Throwable $e) {
            Log::warning('Push badge count failed: '.$e::class);

            return null;
        }
    }

    /**
     * Where a tap on the notification goes: the product page of the bulletin the alert is about, as an app
     * link (`wxnotify://product/{base64url of the page address}`, an https address is not used because Android
     * would open the phone's browser), else the feed. The delivery-log row (the alert's id) does not exist yet.
     * Only a public View Product page address is ever carried.
     *
     * @param  array<string, mixed>  $meta
     */
    private function tapTarget(array $meta): string
    {
        $page = $meta['url'] ?? null;

        if (! is_string($page) || preg_match('#^https://[^/\s?\#]+/view-product/[a-z0-9-]+$#', $page) !== 1) {
            return '/';
        }

        return rtrim((string) config('wxnotify.app_product_uri'), '/').'/'.rtrim(strtr(base64_encode($page), '+/', '-_'), '=');
    }
}

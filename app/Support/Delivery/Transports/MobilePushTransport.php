<?php

namespace App\Support\Delivery\Transports;

use App\Jobs\PruneMobilePushChannel;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\FcmClient;
use Illuminate\Support\Str;

/**
 * Pushes one alert to one signed-in phone. Failure text comes from FcmClient (fixed strings plus a
 * status), never from a response body, an exception or the device token.
 */
class MobilePushTransport implements Transport
{
    /** Long enough for DeliverWeatherAlert to write its delivery-log row first. */
    public const PRUNE_DELAY_SECONDS = 60;

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

        $badge = null;

        if (($channel->config['platform'] ?? null) === 'ios') {
            $badge = $channel->incrementUnreadPushCount();

            if ($badge === null) {
                return DeliveryResult::failed('This device is no longer registered.');
            }
        }

        $result = $this->fcm->send(
            $token,
            $title ?? 'WxNotify',
            Str::limit($body, 200, preserveWords: true),
            ['url' => $this->tapTarget($meta), 'type' => (string) ($meta['type'] ?? 'alert')],
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

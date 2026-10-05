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

        // The tap target is always the feed: the delivery-log row (the alert's id) does not exist yet.
        $result = $this->fcm->send(
            $token,
            $title ?? 'WxNotify',
            Str::limit($body, 200, preserveWords: true),
            ['url' => '/', 'type' => (string) ($meta['type'] ?? 'alert')],
        );

        if ($result->success) {
            return DeliveryResult::delivered();
        }

        if ($result->tokenInvalid) {
            PruneMobilePushChannel::dispatch($channel->id, sha1($token))->delay(self::PRUNE_DELAY_SECONDS);
        }

        return DeliveryResult::failed((string) $result->error);
    }
}

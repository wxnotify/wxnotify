<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Removes a device channel whose FCM token Firebase said is no longer valid. It runs a minute
 * after the failed send so the delivery-log row is written first, and it only deletes a channel
 * that still holds the token that failed: a phone that registered a fresh token in the meantime
 * keeps its channel. The queue payload carries a hash of the token, never the token.
 */
class PruneMobilePushChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $channelId, public string $tokenHash) {}

    public function handle(): void
    {
        $channel = NotificationChannel::query()
            ->where('id', $this->channelId)
            ->where('channel_type', 'mobile_push')
            ->first();

        if ($channel === null) {
            return;
        }

        if (hash_equals($this->tokenHash, sha1((string) ($channel->config['fcm_token'] ?? '')))) {
            $channel->delete();
        }
    }
}

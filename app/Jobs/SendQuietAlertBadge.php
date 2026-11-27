<?php

namespace App\Jobs;

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Updates an iPhone's icon badge for an alert held by quiet time, with a push that carries only the number. The
 * alert is already in the app's feed, so this is a courtesy: one attempt, no delivery-log row, and a failure
 * changes nothing. It stays off the `deliveries` queue so it never goes ahead of a real alert.
 */
class SendQuietAlertBadge implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $channelId)
    {
        $this->onQueue('default');
    }

    public function handle(ChannelDispatcher $dispatcher, CountUnreadAppAlerts $countUnreadAppAlerts): void
    {
        try {
            $channel = NotificationChannel::query()->find($this->channelId);

            // Only an iPhone: Android gets no push for a held alert.
            if ($channel?->channel_type !== 'mobile_push' || ($channel->config['platform'] ?? null) !== 'ios') {
                return;
            }

            $result = $dispatcher->sendAppBadge($channel, $countUnreadAppAlerts($channel->user));

            if (! $result->success) {
                Log::warning('Quiet alert badge push failed: '.$result->error);
            }
        } catch (Throwable $e) {
            // The class only: a message can carry the token or a response body.
            Log::warning('Quiet alert badge push failed: '.$e::class);
        }
    }
}

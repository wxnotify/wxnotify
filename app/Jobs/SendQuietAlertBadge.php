<?php

namespace App\Jobs;

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Updates an iPhone's icon badge for an alert held by quiet time, with a push that carries only the number. The
 * alert is already in the app's feed, so this is a courtesy: one attempt, no delivery-log row, and a failure
 * changes nothing.
 *
 * It waits on the `broadcasts` queue, which the worker reads after `deliveries` and `default`, so waiting badge jobs
 * are not picked ahead of a delivery or of product matching. The one worker does not interrupt a job in hand: a
 * badge already running can still make the next alert wait until it ends (seconds, longer if Firebase is slow).
 *
 * At most one badge job per channel waits at a time (for 10 minutes at most; the lock then expires). The lock is
 * released as processing starts, not after it: an alert held after the running job has counted queues another.
 */
class SendQuietAlertBadge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $channelId)
    {
        $this->onQueue('broadcasts');
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
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

<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

class DispatchChannelConfirmationCode
{
    /**
     * Sends the code through ChannelDispatcher: Apprise for most channel
     * types, Laravel Mail for Email. Returns false both when the message
     * wasn't delivered and, for Apprise-backed channels, when the Apprise API
     * itself is unreachable. The two are kept
     * distinguishable only via the log: AppriseApiClient::notify() isn't
     * changed to swallow ConnectionException itself, since
     * DeliverWeatherAlert/SendActivityAlert share it and rely on that
     * exception surfacing so the queue's own retry-with-backoff handles a
     * transient outage — this synchronous, in-wizard call has no such
     * retry mechanism, so it's the one that needs to catch it.
     */
    public function __invoke(NotificationChannel $channel): bool
    {
        $code = (string) random_int(100000, 999999);

        $channel->forceFill([
            'confirmation_code' => $code,
            'confirmation_code_expires_at' => now()->addMinutes(15),
        ])->save();

        Cache::forget(VerifyChannelConfirmationCode::attemptsKey($channel));

        try {
            return app(ChannelDispatcher::class)->send(
                $channel,
                "Your WxNotify confirmation code is: {$code}\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.",
                'Confirm your WxNotify channel',
                ['type' => 'confirmation', 'code' => $code],
            )->success;
        } catch (ConnectionException $e) {
            logger()->error('Could not reach the Apprise API to dispatch a channel confirmation code', [
                'notification_channel_id' => $channel->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

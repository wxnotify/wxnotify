<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Http\Client\ConnectionException;

class SendTestNotification
{
    /**
     * Returns false both when the channel reports the message wasn't
     * delivered and when its API is unreachable, mirroring
     * DispatchChannelConfirmationCode: this is a synchronous, in-table
     * call with no retry mechanism of its own, so it must catch
     * ConnectionException itself rather than let it surface.
     */
    public function __invoke(NotificationChannel $channel): bool
    {
        try {
            return app(ChannelDispatcher::class)->send(
                $channel,
                "This is a test notification from WxNotify. If you received this, \"{$channel->label}\" is working correctly.",
                'WxNotify test notification',
                ['type' => 'test'],
            )->success;
        } catch (ConnectionException $e) {
            logger()->error('Could not reach the channel to send a test notification', [
                'notification_channel_id' => $channel->id,
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}

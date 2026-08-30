<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;

class DispatchChannelConfirmationCode
{
    public function __invoke(NotificationChannel $channel): bool
    {
        $code = (string) random_int(100000, 999999);

        $channel->forceFill([
            'confirmation_code' => $code,
            'confirmation_code_expires_at' => now()->addMinutes(15),
        ])->save();

        return app(AppriseApiClient::class)->notify(
            url: $channel->apprise_url,
            body: "Your WxNotify confirmation code is: {$code}\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.",
            title: 'Confirm your WxNotify channel',
        );
    }
}

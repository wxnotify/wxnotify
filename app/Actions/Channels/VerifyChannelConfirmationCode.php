<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;

class VerifyChannelConfirmationCode
{
    public function __invoke(NotificationChannel $channel, string $code): bool
    {
        if ($channel->isVerified()) {
            return true;
        }

        if (blank($channel->confirmation_code) || $channel->confirmation_code !== $code) {
            return false;
        }

        if (! $channel->confirmation_code_expires_at || $channel->confirmation_code_expires_at->isPast()) {
            return false;
        }

        $channel->forceFill([
            'verified_at' => now(),
            'confirmation_code' => null,
            'confirmation_code_expires_at' => null,
        ])->save();

        return true;
    }
}

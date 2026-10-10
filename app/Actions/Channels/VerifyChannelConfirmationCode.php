<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Cache;

class VerifyChannelConfirmationCode
{
    /** A six-digit code is guessable, so this many wrong tries clear it and a resend is required. */
    public const MAX_WRONG_CODES = 5;

    public static function attemptsKey(NotificationChannel $channel): string
    {
        return "channel-code-attempts:{$channel->id}";
    }

    public function __invoke(NotificationChannel $channel, string $code): bool
    {
        if ($channel->isVerified()) {
            return true;
        }

        if (blank($channel->confirmation_code)) {
            return false;
        }

        if (! hash_equals((string) $channel->confirmation_code, $code)) {
            $this->recordWrongCode($channel);

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

        Cache::forget(self::attemptsKey($channel));

        return true;
    }

    private function recordWrongCode(NotificationChannel $channel): void
    {
        $key = self::attemptsKey($channel);

        Cache::add($key, 0, now()->addMinutes(30));

        if (Cache::increment($key) < self::MAX_WRONG_CODES) {
            return;
        }

        Cache::forget($key);
        $channel->forceFill(['confirmation_code' => null, 'confirmation_code_expires_at' => null])->save();
    }
}

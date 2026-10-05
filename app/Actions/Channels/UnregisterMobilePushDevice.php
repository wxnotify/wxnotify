<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns push off for one phone: removes the channel its app token registered. Nothing to do (and
 * no error) when the phone never registered or already unregistered.
 */
class UnregisterMobilePushDevice
{
    public function __invoke(User $user, PersonalAccessToken $accessToken): void
    {
        NotificationChannel::query()
            ->where('user_id', $user->id)
            ->where('personal_access_token_id', $accessToken->id)
            ->get()
            ->each(fn (NotificationChannel $channel) => app(CauserResolver::class)->withCauser($user, fn () => $channel->delete()));
    }
}

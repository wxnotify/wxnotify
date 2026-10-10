<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class RenameChannel
{
    public function __invoke(User $user, NotificationChannel $channel, string $label): NotificationChannel
    {
        app(CauserResolver::class)->withCauser($user, fn () => $channel->update(['label' => trim($label)]));

        return $channel;
    }
}

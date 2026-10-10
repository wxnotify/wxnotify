<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelInUse;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelDeletionGuard;
use App\Support\Units;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deletes a channel unless it is some alert's only one (the dashboard's ChannelDeletionGuard:
 * deleting it would leave that alert unable to deliver).
 */
class DeleteChannel
{
    /**
     * @throws ChannelInUse
     */
    public function __invoke(User $user, NotificationChannel $channel): void
    {
        $alerts = ChannelDeletionGuard::alertsLeftWithoutChannel($channel);

        if ($alerts->isNotEmpty()) {
            $units = Units::for($user)->system;

            throw new ChannelInUse($channel->label, $alerts->map(fn (AlertRule $alert): string => $alert->displayName($units))->values()->all());
        }

        app(CauserResolver::class)->withCauser($user, fn () => $channel->delete());
    }
}

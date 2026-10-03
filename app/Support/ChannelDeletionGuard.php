<?php

namespace App\Support;

use App\Models\AlertRule;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which of a subscriber's alerts a deleted notification channel would leave
 * with no channel at all. The alert-to-channel link cascades on delete, so an
 * alert whose only channel is removed survives but can never deliver -- with no
 * warning. An alert that also has another channel just loses this one, which is
 * what deleting it means, so it is not blocked.
 *
 * Used by the subscriber panel's delete actions; staff in the admin panel and
 * the channel-connect flow's cleanup of a half-set-up channel are not guarded.
 */
final class ChannelDeletionGuard
{
    /**
     * @return Collection<int, AlertRule>
     */
    public static function alertsLeftWithoutChannel(NotificationChannel $channel): Collection
    {
        return self::alertsUsing($channel)
            ->has('notificationChannels', '=', 1)
            ->with(['products', 'location'])
            ->orderBy('id')
            ->get();
    }

    /**
     * How many of the owner's alerts use this channel and would keep another.
     */
    public static function alertsKeepingOtherChannels(NotificationChannel $channel): int
    {
        return self::alertsUsing($channel)->has('notificationChannels', '>', 1)->count();
    }

    private static function alertsUsing(NotificationChannel $channel): Builder
    {
        return AlertRule::query()
            ->where('user_id', $channel->user_id)
            ->whereHas('notificationChannels', fn ($query) => $query->where('notification_channels.id', $channel->id));
    }
}

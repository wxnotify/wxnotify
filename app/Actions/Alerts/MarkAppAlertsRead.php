<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marks the subscriber's app alerts read. An alert is read by stamping `read_at` on every successful push row
 * of its group that exists now (rows added since the list was fetched included), in one UPDATE of the rows
 * still unread, so repeating a call changes nothing. Both methods return the new unread count.
 */
class MarkAppAlertsRead
{
    public function __construct(private readonly FindAppAlert $find, private readonly CountUnreadAppAlerts $countUnread) {}

    /**
     * @return int|null the new unread count, or null when the alert is unknown, someone else's or not in the feed
     */
    public function one(User $user, int $id): ?int
    {
        $alert = ($this->find)($user, $id);

        if ($alert === null) {
            return null;
        }

        DeliveryLog::query()
            ->whereKey($alert->pushRows()->modelKeys())
            ->where('user_id', $user->id)
            ->unread()
            ->toBase()
            ->update(['read_at' => now()]);

        return ($this->countUnread)($user);
    }

    /**
     * With a cutoff (the feed's `as_of`), only rows created at or before it are read, so an alert that arrived
     * after the phone loaded its list stays unread. `created_at` is stored to the whole second (truncated), so
     * a stored second is only known to be before the cutoff when it is earlier than the cutoff's own second:
     * a row from that same second stays unread, the safe direction for a warning the subscriber may not have seen.
     */
    public function all(User $user, ?CarbonInterface $through = null): int
    {
        DeliveryLog::query()
            ->where('user_id', $user->id)
            ->pushedToPhone()
            ->unread()
            ->when($through !== null, fn (Builder $query): Builder => $query->where(
                'created_at',
                '<',
                $through->copy()->setTimezone(config('app.timezone'))->startOfSecond(),
            ))
            ->toBase()
            ->update(['read_at' => now()]);

        return ($this->countUnread)($user);
    }
}

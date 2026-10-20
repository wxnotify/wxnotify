<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;

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

    public function all(User $user): int
    {
        DeliveryLog::query()
            ->where('user_id', $user->id)
            ->pushedToPhone()
            ->unread()
            ->toBase()
            ->update(['read_at' => now()]);

        return ($this->countUnread)($user);
    }
}

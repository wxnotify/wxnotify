<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How many of the subscriber's app alerts are unread: the number of feed groups (ListAppAlerts::GROUP_KEY) with
 * at least one unread successful push row, never the number of rows. One query, served by the partial index
 * `delivery_logs_unread_push_index`.
 */
class CountUnreadAppAlerts
{
    public function __invoke(User $user): int
    {
        return DeliveryLog::query()
            ->where('user_id', $user->id)
            ->pushedToPhone()
            ->unread()
            ->distinct()
            ->count(DB::raw('('.ListAppAlerts::GROUP_KEY.')'));
    }
}

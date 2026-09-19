<?php

namespace App\Actions\Admin\FactoryReset;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WipeSubscriberData
{
    /**
     * Deletes every non-staff user (owners are always staff). Locations, rules,
     * channels, templates, pivots and delivery logs cascade via foreign keys;
     * the polymorphic notifications table and sessions have none, so they are
     * cleared explicitly. Deliberately uses query-builder deletes (no model events).
     *
     * @return array{users: int, locations: int, alert_rules: int, channels: int}
     */
    public function __invoke(): array
    {
        $ids = fn (Builder $query) => $query->select('id')->from('users')->where('is_staff', false);

        $counts = [
            'users' => DB::table('users')->where('is_staff', false)->count(),
            'locations' => DB::table('locations')->whereIn('user_id', $ids)->count(),
            'alert_rules' => DB::table('alert_rules')->whereIn('user_id', $ids)->count(),
            'channels' => DB::table('notification_channels')->whereIn('user_id', $ids)->count(),
        ];

        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->delete();
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('users')->where('is_staff', false)->delete();

        return $counts;
    }
}

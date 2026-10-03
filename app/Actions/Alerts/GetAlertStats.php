<?php

namespace App\Actions\Alerts;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;

/**
 * The numbers in the My Alerts stats strip: how many of the subscriber's alerts
 * are active, and what happened to their deliveries over a rolling 7 days
 * (one delivery_logs row per channel, so these are "deliveries", not alerts).
 * Database only, scoped to the given user.
 *
 * The counts use a rolling window (now minus 7 / 14 days) while the sparkline
 * (`daily`) buckets by the subscriber's calendar days, so the sparkline's total
 * can differ slightly from `deliveries`.
 */
class GetAlertStats
{
    /**
     * @return array{active: int, total: int, deliveries: int, previous_deliveries: int, failures: int, held: int, daily: list<int>}
     */
    public function __invoke(User $user): array
    {
        $alerts = AlertRule::query()
            ->where('user_id', $user->id)
            ->selectRaw('count(*) as total, count(*) filter (where is_active) as active')
            ->first();

        $since = now()->subDays(7);
        $before = now()->subDays(14);

        $deliveries = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['success', 'failure', 'suppressed'])
            ->where('dispatched_at', '>=', $before)
            ->selectRaw(
                "count(*) filter (where status = 'success' and dispatched_at >= ?) as deliveries,
                 count(*) filter (where status = 'success' and dispatched_at < ?) as previous_deliveries,
                 count(*) filter (where status = 'failure' and dispatched_at >= ?) as failures,
                 count(*) filter (where status = 'suppressed' and dispatched_at >= ?) as held",
                [$since, $since, $since, $since],
            )
            ->first();

        return [
            'active' => (int) $alerts->active,
            'total' => (int) $alerts->total,
            'deliveries' => (int) $deliveries->deliveries,
            'previous_deliveries' => (int) $deliveries->previous_deliveries,
            'failures' => (int) $deliveries->failures,
            'held' => (int) $deliveries->held,
            'daily' => app(GetAlertDeliveryStats::class)->perDay($user, 7)['counts'],
        ];
    }
}

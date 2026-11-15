<?php

namespace App\Actions\Dashboard;

use App\Models\DeliveryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers behind the admin dashboard's deliveries chart: every subscriber's delivery_logs rows
 * per day, split by outcome (success is "sent"; failure; skipped, which is a delivery the app
 * declined to make, such as a tier or pending-deletion refusal). Days are bucketed in the given
 * timezone; dispatched_at is a naive UTC timestamp, so the bucket reinterprets it as UTC first.
 *
 * The table has no index on dispatched_at alone, so the result is cached briefly.
 */
class GetSystemDeliveryStats
{
    private const CACHE_SECONDS = 300;

    /**
     * @return array{labels: list<string>, sent: list<int>, failed: list<int>, skipped: list<int>}
     */
    public function perDay(string $timezone, int $days): array
    {
        $start = Carbon::now($timezone)->startOfDay()->subDays($days - 1);

        return Cache::remember(
            "admin-delivery-stats:{$timezone}:{$days}:{$start->toDateString()}",
            self::CACHE_SECONDS,
            fn (): array => $this->compute($timezone, $days, $start),
        );
    }

    /**
     * @return array{labels: list<string>, sent: list<int>, failed: list<int>, skipped: list<int>}
     */
    private function compute(string $timezone, int $days, Carbon $start): array
    {
        $rows = DeliveryLog::query()
            ->where('dispatched_at', '>=', $start->copy()->setTimezone(config('app.timezone')))
            ->whereIn('status', ['success', 'failure', 'skipped'])
            ->selectRaw("(dispatched_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as day, status, count(*) as total", [$timezone])
            ->groupBy('day', 'status')
            ->get();

        $count = fn (string $status, string $day): int => (int) $rows->where('status', $status)->firstWhere('day', $day)?->total;

        $result = ['labels' => [], 'sent' => [], 'failed' => [], 'skipped' => []];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $result['labels'][] = $day->format('M j');
            $result['sent'][] = $count('success', $day->toDateString());
            $result['failed'][] = $count('failure', $day->toDateString());
            $result['skipped'][] = $count('skipped', $day->toDateString());
        }

        return $result;
    }
}

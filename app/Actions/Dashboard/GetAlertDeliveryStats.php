<?php

namespace App\Actions\Dashboard;

use App\Enums\AlertCategory;
use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers behind the subscriber dashboard's two alert charts. "An alert
 * sent" is a successful delivery: one delivery_logs row with status 'success'
 * (one per channel); failures, quiet-hours suppression and skipped rows are
 * excluded.
 *
 * Days are bucketed in the timezone of the subscriber's most recently added
 * Location (users have no timezone of their own), falling back to UTC, so an
 * evening alert lands on the right local day. dispatched_at is stored as a
 * naive UTC timestamp, so the bucket expression reinterprets it as UTC and then
 * converts to the subscriber's zone.
 */
class GetAlertDeliveryStats
{
    private const OTHER_LABEL = 'Other';

    public function timezoneFor(User $user): string
    {
        $timezone = $user->locations()->latest()->latest('id')->value('timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * @return array{labels: array<int, string>, counts: array<int, int>, total: int}
     */
    public function perDay(User $user, int $days): array
    {
        $timezone = $this->timezoneFor($user);
        $start = $this->rangeStart($timezone, $days);

        $perDay = $this->successful($user, $start)
            ->selectRaw("(dispatched_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date as day, count(*) as total", [$timezone])
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $counts = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = $day->format('M j');
            $counts[] = (int) ($perDay[$day->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'counts' => $counts, 'total' => array_sum($counts)];
    }

    /**
     * @return array<int, array{category: ?string, label: string, count: int}>
     */
    public function byCategory(User $user, int $days): array
    {
        $start = $this->rangeStart($this->timezoneFor($user), $days);

        $counts = [];
        $other = 0;

        $this->successful($user, $start)
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->get()
            ->each(function ($row) use (&$counts, &$other): void {
                $category = AlertCategory::tryFrom((string) $row->category);

                if ($category === null) {
                    $other += (int) $row->total;

                    return;
                }

                $counts[$category->value] = (int) $row->total;
            });

        $slices = collect($counts)
            ->map(fn (int $count, string $value): array => [
                'category' => $value,
                'label' => AlertCategory::from($value)->label(),
                'count' => $count,
            ])
            ->sort(fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']])
            ->values()
            ->all();

        if ($other > 0) {
            $slices[] = ['category' => null, 'label' => self::OTHER_LABEL, 'count' => $other];
        }

        return $slices;
    }

    private function rangeStart(string $timezone, int $days): Carbon
    {
        return Carbon::now($timezone)->startOfDay()->subDays($days - 1);
    }

    /**
     * @return Builder<DeliveryLog>
     */
    private function successful(User $user, Carbon $start): Builder
    {
        return DeliveryLog::query()
            ->where('user_id', $user->id)
            ->where('status', 'success')
            ->where('dispatched_at', '>=', $start->copy()->setTimezone(config('app.timezone')));
    }
}

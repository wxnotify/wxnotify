<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AppAlert;
use App\Support\AppAlertCursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A page of the subscriber's alerts for the mobile app, newest first. Deliveries of one rule
 * and product collapse into one alert; ordering is by the group's latest delivery time, then
 * its id, and the cursor carries both so pages are stable when times tie.
 *
 * Only alerts that reached one of the subscriber's phones are listed: a group is in the feed when at least one
 * of its rows is a successful `mobile_push` (DeliveryLog::scopePushedToPhone()). The group's id, time and cursor
 * still come from all of its rows, so they are the same as before the feed was narrowed.
 */
class ListAppAlerts
{
    /** Mirrors AppAlert::keyFor(). */
    public const GROUP_KEY = "CASE WHEN ingested_product_id IS NULL THEN 'row-' || id ELSE COALESCE(alert_rule_id::text, 'none') || '-' || ingested_product_id END";

    /**
     * True for a group with at least one successful push row; mirrors DeliveryLog::scopePushedToPhone(). It is a
     * column of the grouped subquery filtered outside it, not a HAVING: on Postgres the HAVING form tipped the
     * planner into sorting every row by the text group key (about 4x slower on 160k rows of one user), while
     * this form keeps today's hash aggregate.
     */
    public const HAS_PUSH = "bool_or(channel_type = 'mobile_push' AND status = 'success')";

    /**
     * @return array{alerts: Collection<int, AppAlert>, next_cursor: ?string}
     */
    public function __invoke(User $user, int $limit = 25, ?string $before = null): array
    {
        $cursor = $before === null ? null : AppAlertCursor::decode($before);

        $grouped = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->selectRaw('MIN(id) AS entry_id, MAX(dispatched_at) AS sort_at, '.self::HAS_PUSH.' AS has_push')
            ->groupByRaw(self::GROUP_KEY)
            ->when($cursor, fn ($query) => $query->havingRaw('(MAX(dispatched_at), MIN(id)) < (?, ?)', [$cursor['at'], $cursor['id']]))
            ->toBase();

        $groups = DB::query()
            ->fromSub($grouped, 'alert_groups')
            ->select(['entry_id', 'sort_at'])
            ->where('has_push', true)
            ->orderByDesc('sort_at')
            ->orderByDesc('entry_id')
            ->limit($limit + 1)
            ->get();

        $page = $groups->take($limit);
        $last = $page->last();

        return [
            'alerts' => $this->assemble($user, $page),
            'next_cursor' => $groups->count() > $limit && $last !== null
                ? AppAlertCursor::encode($last->sort_at, (int) $last->entry_id)
                : null,
        ];
    }

    /**
     * @param  Collection<int, object{entry_id: int}>  $page
     * @return Collection<int, AppAlert>
     */
    private function assemble(User $user, Collection $page): Collection
    {
        if ($page->isEmpty()) {
            return collect();
        }

        $entryIds = $page->pluck('entry_id');

        $anchors = DeliveryLog::query()->where('user_id', $user->id)->whereIn('id', $entryIds)->get()->keyBy('id');
        $productIds = $anchors->pluck('ingested_product_id')->filter()->unique();

        $byKey = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereIn('id', $entryIds)->orWhereIn('ingested_product_id', $productIds))
            ->with(['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct:id,view_slug'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DeliveryLog $row) => AppAlert::keyFor($row));

        return $page
            ->map(fn (object $group) => new AppAlert($byKey[AppAlert::keyFor($anchors[$group->entry_id])]->values()))
            ->values();
    }
}

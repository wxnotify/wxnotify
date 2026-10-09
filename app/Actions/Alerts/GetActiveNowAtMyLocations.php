<?php

namespace App\Actions\Alerts;

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The warnings/watches/advisories in effect at any of a subscriber's locations,
 * grouped by event, each with whether the subscriber's own alerts told them.
 *
 * Coverage is evidence, not prediction: it comes from the subscriber's
 * delivery_logs rows for the same VTEC event (the five vtec_* columns an
 * IngestedProduct shares with ActiveWwa::vtecKeyFor(), non-test only), so it
 * reuses the real matching result instead of duplicating MatchWeatherAlertRules.
 * A delivery that has linked events (delivery_log_segment) covers exactly those
 * events; a delivery logged before segments existed has none and is matched
 * through its bulletin's vtec_* columns.
 * A warning issued before an alert existed therefore reads "none". Precedence
 * per event: alerted, held (quiet hours), failed, none.
 *
 * A delivery cannot predate the event's first ingest, so the query is bounded
 * to one day before the active row was created (and to the three statuses the
 * (user_id, status, dispatched_at) index serves).
 *
 * Database only; cached 60 s per subscriber; looks at the first 25 locations
 * with a position and shows the first 6 events.
 */
class GetActiveNowAtMyLocations
{
    private const MAX_LOCATIONS = 25;

    private const MAX_EVENTS = 6;

    private const CACHE_SECONDS = 60;

    /**
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, has_position: bool, capped: bool}
     */
    public function __invoke(User $user): array
    {
        return Cache::remember(
            "my-alerts-active-now:{$user->id}",
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, has_position: bool, capped: bool}
     */
    private function compute(User $user): array
    {
        $withPosition = $user->locations()->whereNotNull('lat')->whereNotNull('lng')->orderBy('id')->limit(self::MAX_LOCATIONS + 1)->get();

        if ($withPosition->isEmpty()) {
            return ['events' => [], 'more' => 0, 'has_locations' => $user->locations()->exists(), 'has_position' => false, 'capped' => false];
        }

        $capped = $withPosition->count() > self::MAX_LOCATIONS;
        $lookup = app(GetActiveAlertsForLocation::class);

        /** @var array<int, array{wwa: ActiveWwa, locations: list<Location>}> $grouped */
        $grouped = [];

        foreach ($withPosition->take(self::MAX_LOCATIONS) as $location) {
            foreach ($lookup->activeFor($location) as $wwa) {
                $grouped[$wwa->id]['wwa'] ??= $wwa;
                $grouped[$wwa->id]['locations'][] = $location;
            }
        }

        $sorted = GetActiveAlertsForLocation::sorted(collect($grouped)->map(fn (array $group): ActiveWwa => $group['wwa']));
        $shown = $sorted->take(self::MAX_EVENTS);

        $events = $shown->map(fn (ActiveWwa $wwa): array => $this->eventRow($user, $wwa, $grouped[$wwa->id]['locations']))->values()->all();

        return ['events' => $events, 'more' => max(0, $sorted->count() - self::MAX_EVENTS), 'has_locations' => true, 'has_position' => true, 'capped' => $capped];
    }

    /**
     * @param  list<Location>  $locations
     * @return array<string, mixed>
     */
    private function eventRow(User $user, ActiveWwa $wwa, array $locations): array
    {
        $locations = collect($locations)->sortBy('label')->values();
        $timezone = Timezones::forLocation($locations->first()) ?? config('app.timezone');

        $logs = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['success', 'suppressed', 'failure'])
            ->where('dispatched_at', '>=', $wwa->created_at->copy()->subDay())
            ->where(function (Builder $query) use ($wwa): void {
                $query->whereHas('segments', function (Builder $segments) use ($wwa): void {
                    $segments->where(ActiveWwa::vtecKeyFor($wwa))
                        ->whereHas('ingestedProduct', fn (Builder $bulletin) => $bulletin->where('is_test', false));
                })->orWhere(function (Builder $unlinked) use ($wwa): void {
                    $unlinked->doesntHave('segments')
                        ->whereIn('ingested_product_id', IngestedProduct::query()->select('id')->where(ActiveWwa::vtecKeyFor($wwa))->where('is_test', false));
                });
            })
            ->with(['alertRule.products', 'alertRule.location'])
            ->orderByDesc('dispatched_at')
            ->get();

        $successes = $logs->where('status', 'success');
        $status = match (true) {
            $successes->isNotEmpty() => 'alerted',
            $logs->where('status', 'suppressed')->isNotEmpty() => 'held',
            $logs->where('status', 'failure')->isNotEmpty() => 'failed',
            default => 'none',
        };

        return [
            'key' => $wwa->id,
            'event' => $wwa->eventName(),
            'family' => $wwa->eventFamily(),
            'locations' => $locations->pluck('label')->map(fn ($label): string => (string) $label)->all(),
            'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
            'status' => $status,
            'alerted_via' => $successes->map(fn (DeliveryLog $log): string => $log->alertRule?->displayName() ?? 'one of your alerts')->unique()->values()->all(),
            'alerted_at' => $successes->first()?->dispatched_at?->copy()->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

<?php

namespace App\Actions\Alerts;

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;
use Illuminate\Support\Facades\Cache;

/**
 * The warnings/watches/advisories in effect at any of a subscriber's locations,
 * grouped by event, each with whether the subscriber's own alerts told them.
 *
 * Coverage is evidence, not prediction: it comes from the subscriber's
 * delivery_logs rows for the same VTEC event (the five vtec_* columns an
 * IngestedProduct shares with ActiveWwa::vtecKeyFor(), non-test only), so it
 * reuses the real matching result instead of duplicating MatchWeatherAlertRules.
 * A warning issued before an alert existed therefore reads "none". Precedence
 * per event: alerted, held (quiet hours), failed, none.
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
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, capped: bool}
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
     * @return array{events: list<array<string, mixed>>, more: int, has_locations: bool, capped: bool}
     */
    private function compute(User $user): array
    {
        $withPosition = $user->locations()->orderBy('id')->get()->filter(fn (Location $location): bool => $location->hasFix())->values();

        if ($withPosition->isEmpty()) {
            return ['events' => [], 'more' => 0, 'has_locations' => $user->locations()->exists(), 'capped' => false];
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

        return ['events' => $events, 'more' => max(0, $sorted->count() - self::MAX_EVENTS), 'has_locations' => true, 'capped' => $capped];
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
            ->whereIn('ingested_product_id', IngestedProduct::query()->select('id')->where(ActiveWwa::vtecKeyFor($wwa))->where('is_test', false))
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
            'event' => $wwa->product?->name ?? 'Weather alert',
            'family' => $wwa->product?->family?->value,
            'locations' => $locations->pluck('label')->map(fn ($label): string => (string) $label)->all(),
            'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
            'status' => $status,
            'alerted_via' => $successes->map(fn (DeliveryLog $log): string => $log->alertRule?->displayName() ?? 'one of your alerts')->unique()->values()->all(),
            'alerted_at' => $successes->first()?->dispatched_at?->copy()->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

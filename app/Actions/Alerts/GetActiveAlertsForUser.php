<?php

namespace App\Actions\Alerts;

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

/**
 * The warnings, watches and advisories in effect at any of a subscriber's located places (the phone
 * included), one entry per event with the labels of the places it affects, for the app's Alerts
 * screen. Events come only from GetActiveAlertsForLocation::activeFor() (the zone rows), never an
 * event's own columns. Test products and every other family are left out. Looks at the first 25
 * located places; cached 60 seconds per subscriber.
 */
class GetActiveAlertsForUser
{
    private const MAX_LOCATIONS = 25;

    private const CACHE_SECONDS = 60;

    private const FAMILIES = ['warning', 'watch', 'advisory'];

    /**
     * @return array{events: list<array{id: int, event: string, family: string, places: list<string>, expires: ?string, view_url: ?string}>, has_places: bool, generated_at: string}
     */
    public function __invoke(User $user): array
    {
        return Cache::remember(
            "app-active-alerts:{$user->id}",
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return array{events: list<array<string, mixed>>, has_places: bool, generated_at: string}
     */
    private function compute(User $user): array
    {
        $locations = $user->locations()->whereNotNull('lat')->whereNotNull('lng')->orderBy('id')->limit(self::MAX_LOCATIONS)->get();

        if ($locations->isEmpty()) {
            return ['events' => [], 'has_places' => false, 'generated_at' => now()->toIso8601String()];
        }

        $lookup = app(GetActiveAlertsForLocation::class);

        /** @var array<int, array{wwa: ActiveWwa, places: list<string>, ends: list<?CarbonInterface>}> $grouped */
        $grouped = [];

        foreach ($locations as $location) {
            foreach ($lookup->activeFor($location) as $wwa) {
                if (! in_array($wwa->eventFamily(), self::FAMILIES, true)) {
                    continue;
                }

                $grouped[$wwa->id]['wwa'] ??= $wwa;
                $grouped[$wwa->id]['places'][] = (string) $location->label;
                $grouped[$wwa->id]['ends'][] = $wwa->expires;
            }
        }

        $wwas = new EloquentCollection(array_map(function (array $group): ActiveWwa {
            $group['wwa']->expires = in_array(null, $group['ends'], true) ? null : collect($group['ends'])->max();

            return $group['wwa'];
        }, array_values($grouped)));

        $wwas->load('latestIngestedProduct');

        $events = GetActiveAlertsForLocation::sorted($wwas)
            ->map(function (ActiveWwa $wwa) use ($grouped): array {
                $places = collect($grouped[$wwa->id]['places'])->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

                return [
                    'id' => $wwa->id,
                    'event' => $wwa->eventName(),
                    'family' => (string) $wwa->eventFamily(),
                    'places' => $places,
                    'expires' => $wwa->expires?->toIso8601String(),
                    'view_url' => $wwa->latestIngestedProduct?->viewUrl(),
                ];
            })
            ->values()
            ->all();

        return ['events' => $events, 'has_places' => true, 'generated_at' => now()->toIso8601String()];
    }
}

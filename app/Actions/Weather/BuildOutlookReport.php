<?php

namespace App\Actions\Weather;

use App\Enums\OutlookCategory;
use App\Models\IngestedProduct;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * Turns the FetchSpcOutlooks result into the plain-data outlook report payload for the chosen locations.
 *
 * A day that is null, or lacks its category layer (days 1-3) or its probability layer (days 4-8), is
 * unavailable; an empty layer is a valid quiet day. A Day 1 without `cat` is unavailable like in
 * Day1OutlookFreshness, which also sets `day1_missing`. On an available day a level whose own file
 * failed (hail, wind, tornado on Days 1-2; any_severe on Day 3) is listed in `missing`, never read as
 * none. Locations without a fix are skipped.
 *
 * @phpstan-type Row array{label: string, category: string, hail: ?string, wind: ?string, tornado: ?string}
 */
class BuildOutlookReport
{
    private const PWO_TITLE = 'Public Severe Weather Outlook';

    public function __construct(private LocationsInOutlookAreas $locationsIn) {}

    /**
     * @param  array<string, mixed>  $outlooks
     * @param  Collection<int, Location>  $locations
     * @return array<string, mixed>
     */
    public function __invoke(array $outlooks, Collection $locations, ?IngestedProduct $pwo = null): array
    {
        $locations = $locations->filter(fn (Location $location): bool => $location->hasFix())->values();

        $days = [];
        foreach ([1, 2, 3] as $day) {
            $days[$day] = $this->convectiveDay($outlooks["day_{$day}"] ?? null, $day, $locations);
        }

        $extended = [];
        foreach ([4, 5, 6, 7, 8] as $day) {
            $extended[$day] = $this->extendedDay($outlooks["day_{$day}"] ?? null, $locations);
        }

        $day1Category = $days[1]['available'] ? OutlookCategory::tryFrom((string) $days[1]['category']) : null;

        return [
            'days' => $days,
            'extended' => $extended,
            'pwo' => $pwo !== null && $day1Category !== null && $day1Category->rank() >= OutlookCategory::Moderate->rank()
                ? [
                    'title' => $pwo->product?->name ?? self::PWO_TITLE,
                    'text' => (string) $pwo->text,
                    'issued_at' => (string) $pwo->received_at?->toIso8601String(),
                ]
                : null,
            'something_forecast' => $this->somethingForecast($days, $extended),
            'day1_missing' => ! $days[1]['available'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $outlook
     * @param  Collection<int, Location>  $locations
     * @return array<string, mixed>
     */
    private function convectiveDay(?array $outlook, int $day, Collection $locations): array
    {
        $layers = $outlook['layers'] ?? [];

        if ($outlook === null || ! array_key_exists('cat', $layers)) {
            return [
                'available' => false, 'issued_at' => null, 'category' => null,
                'hail' => null, 'wind' => null, 'tornado' => null, 'any_severe' => null, 'missing' => [], 'locations' => [],
            ];
        }

        $missing = $this->missingLevels($day, $layers);

        $categoryByLocation = $this->highestPerLocation($layers['cat'], $locations, fn (string $label): int => OutlookCategory::fromLabel($label)->rank());
        $hail = $this->highestPerLocation($layers['hail'] ?? [], $locations, $this->probabilityRank(...));
        $wind = $this->highestPerLocation($layers['wind'] ?? [], $locations, $this->probabilityRank(...));
        $tornado = $this->highestPerLocation($layers['torn'] ?? [], $locations, $this->probabilityRank(...));

        $rows = $locations->map(fn (Location $location): array => [
            'label' => (string) $location->label,
            'category' => $this->categoryForRank($categoryByLocation[$location->id] ?? null)->value,
            'hail' => $this->percent($hail[$location->id] ?? null),
            'wind' => $this->percent($wind[$location->id] ?? null),
            'tornado' => $this->percent($tornado[$location->id] ?? null),
        ])->all();

        return [
            'available' => true,
            'issued_at' => $outlook['issued_at'] ?? null,
            'category' => $this->categoryForRank($this->highestRank($layers['cat'], fn (string $label): int => OutlookCategory::fromLabel($label)->rank()))->value,
            'hail' => $this->percent($this->highestRank($layers['hail'] ?? [], $this->probabilityRank(...))),
            'wind' => $this->percent($this->highestRank($layers['wind'] ?? [], $this->probabilityRank(...))),
            'tornado' => $this->percent($this->highestRank($layers['torn'] ?? [], $this->probabilityRank(...))),
            'any_severe' => $day === 3 ? $this->percent($this->highestRank($layers['prob'] ?? [], $this->probabilityRank(...))) : null,
            'missing' => $missing,
            'locations' => $rows,
        ];
    }

    /**
     * The levels whose file could not be fetched (absent layer), as opposed to an empty layer, which is
     * a real "none": hail, wind and tornado on Days 1-2, any_severe on Day 3. Their values stay null.
     *
     * @param  array<string, mixed>  $layers
     * @return list<string>
     */
    private function missingLevels(int $day, array $layers): array
    {
        $levels = $day === 3 ? ['any_severe' => 'prob'] : ['hail' => 'hail', 'wind' => 'wind', 'tornado' => 'torn'];

        return array_keys(array_filter($levels, fn (string $layer): bool => ! array_key_exists($layer, $layers)));
    }

    /**
     * @param  array<string, mixed>|null  $outlook
     * @param  Collection<int, Location>  $locations
     * @return array<string, mixed>
     */
    private function extendedDay(?array $outlook, Collection $locations): array
    {
        $layers = $outlook['layers'] ?? [];

        if ($outlook === null || ! array_key_exists('prob', $layers)) {
            return ['available' => false, 'probability' => null, 'category' => null, 'highlight' => false, 'locations' => []];
        }

        $highest = $this->highestRank($layers['prob'], $this->probabilityRank(...));
        $highlight = $highest !== null && $highest >= 15;

        $inside = [];
        if ($highlight) {
            $found = $this->highestPerLocation($layers['prob'], $locations, $this->probabilityRank(...));
            $inside = $locations
                ->filter(fn (Location $location): bool => ($found[$location->id] ?? 0) >= 15)
                ->map(fn (Location $location): string => (string) $location->label)
                ->values()
                ->all();
        }

        return [
            'available' => true,
            'probability' => $this->percent($highest),
            'category' => OutlookCategory::fromProbability(($highest ?? 0) / 100)->value,
            'highlight' => $highlight,
            'locations' => $inside,
        ];
    }

    /**
     * @param  list<array{label: string, geometry: array<string, mixed>}>  $features
     * @param  callable(string): int  $rank
     */
    private function highestRank(array $features, callable $rank): ?int
    {
        $highest = null;

        foreach ($features as $feature) {
            $value = $rank((string) ($feature['label'] ?? ''));

            if ($value > 0 && ($highest === null || $value > $highest)) {
                $highest = $value;
            }
        }

        return $highest;
    }

    /**
     * The highest rank (above zero) of the features whose geometry contains each location, by location id.
     *
     * @param  list<array{label: string, geometry: array<string, mixed>}>  $features
     * @param  Collection<int, Location>  $locations
     * @param  callable(string): int  $rank
     * @return array<int, int>
     */
    private function highestPerLocation(array $features, Collection $locations, callable $rank): array
    {
        $best = [];

        if ($locations->isEmpty()) {
            return $best;
        }

        foreach ($features as $feature) {
            $value = $rank((string) ($feature['label'] ?? ''));

            if ($value <= 0) {
                continue;
            }

            foreach (($this->locationsIn)($feature['geometry'] ?? [], $locations) as $id) {
                if ($value > ($best[$id] ?? 0)) {
                    $best[$id] = $value;
                }
            }
        }

        return $best;
    }

    /**
     * A decimal label such as `0.15` as whole percent (15); anything else is 0.
     */
    private function probabilityRank(string $label): int
    {
        return is_numeric($label) ? (int) round((float) $label * 100) : 0;
    }

    private function percent(?int $value): ?string
    {
        return $value === null ? null : "{$value}%";
    }

    private function categoryForRank(?int $rank): OutlookCategory
    {
        foreach (OutlookCategory::cases() as $case) {
            if ($case->rank() === ($rank ?? 0)) {
                return $case;
            }
        }

        return OutlookCategory::None;
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $extended
     */
    private function somethingForecast(array $days, array $extended): bool
    {
        foreach ($days as $day) {
            $category = $day['available'] ? OutlookCategory::tryFrom((string) $day['category']) : null;

            if ($category !== null && $category->rank() >= OutlookCategory::Marginal->rank()) {
                return true;
            }
        }

        foreach ($extended as $day) {
            if ($day['available'] && $day['highlight']) {
                return true;
            }
        }

        return false;
    }
}

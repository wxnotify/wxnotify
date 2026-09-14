<?php

namespace App\Actions\Weather;

use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Models\AlertRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Checks a Threshold Alert's variable/direction/threshold_value against the
 * relevant window of GetGridpointForecastData's series for the rule's
 * timeframe. Returns the most extreme qualifying reading (the highest value
 * for "rises above", the lowest for "falls below") so the delivered message
 * can state a concrete peak rather than just "yes, it was crossed" — the
 * same plain-language-with-a-real-number treatment this app already gives
 * Heads Up and Lightning Alerts. Returns null when nothing in the window
 * crosses the threshold.
 */
class EvaluateThresholdAlert
{
    /**
     * @param  array{periods: array<int, array<string, mixed>>, series: array<string, array<int, array{start: Carbon, end: Carbon, value: float}>>}  $gridpointData
     * @return array{value: float, at: Carbon}|null
     */
    public function __invoke(AlertRule $rule, array $gridpointData): ?array
    {
        $window = $this->resolveWindow($rule->timeframe, $gridpointData['periods']);

        if ($window === null) {
            return null;
        }

        [$start, $end] = $window;

        $series = collect($gridpointData['series'][$rule->variable->value] ?? []);

        $inWindow = $series->filter(fn (array $entry): bool => $entry['start']->lt($end) && $entry['end']->gt($start));

        $threshold = (float) $rule->threshold_value;

        $qualifying = $inWindow->filter(fn (array $entry): bool => $rule->direction === ThresholdDirection::Above
            ? $entry['value'] > $threshold
            : $entry['value'] < $threshold);

        if ($qualifying->isEmpty()) {
            return null;
        }

        $peak = $rule->direction === ThresholdDirection::Above
            ? $qualifying->sortByDesc('value')->first()
            : $qualifying->sortBy('value')->first();

        return ['value' => $peak['value'], 'at' => $peak['start']];
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveWindow(ThresholdTimeframe $timeframe, array $periods): ?array
    {
        $day = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === true);
        $night = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === false);

        return match ($timeframe) {
            ThresholdTimeframe::Day => $day ? [Carbon::parse($day['startTime']), Carbon::parse($day['endTime'])] : null,
            ThresholdTimeframe::Overnight => $night ? [Carbon::parse($night['startTime']), Carbon::parse($night['endTime'])] : null,
            ThresholdTimeframe::AnyTime => $this->combinedWindow($day, $night),
        };
    }

    /**
     * @param  array<string, mixed>|null  $day
     * @param  array<string, mixed>|null  $night
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function combinedWindow(?array $day, ?array $night): ?array
    {
        if ($day === null && $night === null) {
            return null;
        }

        /** @var Collection<int, Carbon> $starts */
        $starts = collect([$day, $night])->filter()->map(fn (array $p): Carbon => Carbon::parse($p['startTime']));
        /** @var Collection<int, Carbon> $ends */
        $ends = collect([$day, $night])->filter()->map(fn (array $p): Carbon => Carbon::parse($p['endTime']));

        return [$starts->min(), $ends->max()];
    }
}

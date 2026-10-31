<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Whether the Day 1 outlook fetched at send time is the cycle that should be out by then,
 * rather than a previous cycle still being served.
 *
 * A Day 1 missing any of its `cat`, `torn`, `hail` or `wind` layers (that file failed while others
 * parsed) is not current, so the sender retries; an empty layer is a valid quiet one. Callers (the
 * report presenter) must likewise treat a Day 1 without `cat` as unavailable.
 */
class Day1OutlookFreshness
{
    /** Issue times of the Day 1 outlook, UTC (hour, minute). */
    private const CYCLES = [[1, 0], [6, 0], [13, 0], [16, 30], [20, 0]];

    /** Every Day 1 file the report reads; one that failed is retried like a stale cycle. */
    private const REQUIRED_LAYERS = ['cat', 'torn', 'hail', 'wind'];

    /**
     * @param  array{issued_at?: ?string, last_modified?: ?string, layers?: array<string, mixed>}|null  $day1
     * @param  CarbonInterface  $sendTime  an instant in any timezone (the owner's local send time); the cycles are UTC, so it is converted to UTC first
     */
    public static function isCurrent(?array $day1, CarbonInterface $sendTime): bool
    {
        $layers = $day1['layers'] ?? [];

        foreach (self::REQUIRED_LAYERS as $layer) {
            if (! array_key_exists($layer, $layers)) {
                return false;
            }
        }

        $stamp = self::parse($day1['issued_at'] ?? null) ?? self::parse($day1['last_modified'] ?? null);

        if ($stamp === null) {
            return true;
        }

        return $stamp->greaterThanOrEqualTo(self::expectedCycle($sendTime)->subMinutes(15));
    }

    private static function expectedCycle(CarbonInterface $sendTime): CarbonImmutable
    {
        $limit = CarbonImmutable::instance($sendTime)->utc()->subMinutes(15);
        $expected = null;

        foreach (self::CYCLES as [$hour, $minute]) {
            $cycle = $limit->setTime($hour, $minute);

            if ($cycle->lessThanOrEqualTo($limit)) {
                $expected = $cycle;
            }
        }

        return $expected ?? $limit->subDay()->setTime(20, 0);
    }

    private static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Whether the Day 1 outlook fetched at send time is the cycle that should be out by then,
 * rather than a previous cycle still being served.
 *
 * A Day 1 without a `cat` layer (the file failed while others parsed) is not current; an empty
 * `cat` list is a valid quiet day. Callers (the report presenter) must likewise treat a Day 1
 * without `cat` as unavailable.
 */
class Day1OutlookFreshness
{
    /** Issue times of the Day 1 outlook, UTC (hour, minute). */
    private const CYCLES = [[1, 0], [6, 0], [13, 0], [16, 30], [20, 0]];

    /**
     * @param  array{issued_at?: ?string, last_modified?: ?string, layers?: array<string, mixed>}|null  $day1
     */
    public static function isCurrent(?array $day1, CarbonInterface $sendTime): bool
    {
        if ($day1 === null || ! array_key_exists('cat', $day1['layers'] ?? [])) {
            return false;
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

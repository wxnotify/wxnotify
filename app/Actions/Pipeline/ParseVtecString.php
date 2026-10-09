<?php

namespace App\Actions\Pipeline;

use Illuminate\Support\Carbon;

/**
 * Parses a P-VTEC string (/k.aaa.cccc.pp.s.####.YYMMDDTHHNNZ-YYMMDDTHHNNZ/)
 * into its components. Used identically whether the string came from a
 * regex match against raw bulletin text or from CAP's own VTEC parameter --
 * the format is the same either way.
 *
 * `year` is derived from the current wall-clock time, not from either the
 * VTEC string's begin or end timestamp: VTEC event-tracking numbers are
 * scoped to the calendar year an event is currently being tracked in, and
 * `end` in particular is `000000T0000Z` ("until further notice") for an
 * entire class of real products (long-fuse hydrologic warnings, all
 * tropical products) -- Carbon normalizes that degenerate string to
 * 1999-11-30, which would silently corrupt the composite key used to
 * identify a single real-world event across all its issuances.
 */
class ParseVtecString
{
    private const PATTERN = '/\/[A-Z]\.([A-Z]{3})\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\.(\d{6}T\d{4}Z)-(\d{6}T\d{4}Z)\//';

    /**
     * @return ?array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: ?Carbon}
     */
    public function __invoke(string $text): ?array
    {
        return $this->all($text)[0] ?? null;
    }

    /**
     * Every P-VTEC string in the text, in the order they appear. A product section can carry
     * several (one per event it covers, e.g. a Hurricane Watch and a Small Craft Advisory over the
     * same waters), and a bulletin several sections.
     *
     * @return list<array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: ?Carbon}>
     */
    public function all(string $text): array
    {
        if (! preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        return array_map($this->parts(...), $matches);
    }

    /**
     * @param  array<int, string>  $matches
     * @return array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: ?Carbon}
     */
    private function parts(array $matches): array
    {
        [, $action, $office, $phenomenon, $significance, $eventNumber, $beginRaw, $endRaw] = $matches;

        return [
            'office' => $office,
            'phenomenon' => $phenomenon,
            'significance' => $significance,
            'event_number' => $eventNumber,
            'year' => now()->year,
            'action' => $action,
            'begin' => str_starts_with($beginRaw, '000000T0000') ? null : Carbon::createFromFormat('ymd\THi\Z', $beginRaw, 'UTC'),
            'end' => str_starts_with($endRaw, '000000T0000') ? null : Carbon::createFromFormat('ymd\THi\Z', $endRaw, 'UTC'),
        ];
    }
}

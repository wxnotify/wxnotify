<?php

namespace App\Actions\Pipeline;

use Illuminate\Support\Carbon;

/**
 * Parses a P-VTEC string (/k.aaa.cccc.pp.s.####.YYMMDDTHHNNZ-YYMMDDTHHNNZ/)
 * into its components. Used identically whether the string came from a
 * regex match against raw bulletin text or from CAP's own VTEC parameter --
 * the format is the same either way.
 */
class ParseVtecString
{
    private const PATTERN = '/\/[A-Z]\.([A-Z]{3})\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\.(\d{6}T\d{4}Z)-(\d{6}T\d{4}Z)\//';

    /**
     * @return ?array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: Carbon}
     */
    public function __invoke(string $text): ?array
    {
        if (! preg_match(self::PATTERN, $text, $matches)) {
            return null;
        }

        [, $action, $office, $phenomenon, $significance, $eventNumber, $beginRaw, $endRaw] = $matches;

        $end = $this->parseTimestamp($endRaw);

        return [
            'office' => $office,
            'phenomenon' => $phenomenon,
            'significance' => $significance,
            'event_number' => $eventNumber,
            'year' => $end->year,
            'action' => $action,
            'begin' => str_starts_with($beginRaw, '000000T0000') ? null : $this->parseTimestamp($beginRaw),
            'end' => $end,
        ];
    }

    /**
     * Converts a VTEC YYMMDDTHHNNZ timestamp to an ISO-8601 string and
     * parses it via Carbon::parse() rather than createFromFormat() with an
     * explicit 'UTC' timezone -- the latter produces a named-timezone
     * Carbon instance (timezone_type 3, "UTC") whose internal
     * representation differs from the offset-style ("Z", timezone_type 2)
     * instance Carbon::parse('...Z') produces, which fails strict
     * (toBe()/assertSame) comparisons against callers/tests that build
     * their expected value with Carbon::parse().
     */
    private function parseTimestamp(string $raw): Carbon
    {
        return Carbon::parse(sprintf(
            '20%s-%s-%sT%s:%s:00Z',
            substr($raw, 0, 2),
            substr($raw, 2, 2),
            substr($raw, 4, 2),
            substr($raw, 7, 2),
            substr($raw, 9, 2),
        ));
    }
}

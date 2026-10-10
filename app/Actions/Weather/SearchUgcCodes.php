<?php

namespace App\Actions\Weather;

use Illuminate\Support\Facades\DB;

/**
 * Counties and forecast zones as the UGC codes the alert form stores (county SSCnnn, zone SSZnnn),
 * searched by state and text and paged by the last code seen. counties.fips is a raw 5-digit
 * FIPS code and public_zones.state_zone omits the type letter, so both codes are derived here,
 * the way the dashboard form derives them.
 */
class SearchUgcCodes
{
    private const SOURCE = <<<'SQL'
        SELECT upper(state) || 'C' || right(fips, 3) AS code,
               upper(state) || 'C' || right(fips, 3) || ' — ' || countyname || ' County, ' || state AS label,
               upper(state) AS st,
               countyname AS name
        FROM counties
        UNION ALL
        SELECT upper(state) || 'Z' || zone AS code,
               upper(state) || 'Z' || zone || ' — ' || name || ', ' || state AS label,
               upper(state) AS st,
               name
        FROM public_zones
    SQL;

    /**
     * @return list<array{code: string, label: string}>
     */
    public function __invoke(?string $state, ?string $query, int $limit, ?string $after = null): array
    {
        $state = strtoupper((string) $state);
        $text = trim((string) $query);
        $after = (string) $after;
        $needle = '%'.addcslashes($text, '%_\\').'%';

        return collect(DB::select(
            'SELECT code, label FROM ('.self::SOURCE.') AS ugc
             WHERE (? = \'\' OR st = ?)
               AND (? = \'\' OR name ILIKE ? OR code ILIKE ?)
               AND (? = \'\' OR code > ?)
             ORDER BY code
             LIMIT ?',
            [$state, $state, $text, $needle, $needle, $after, $after, $limit],
        ))->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all();
    }

    public function exists(string $code): bool
    {
        if (preg_match('/^([A-Z]{2})([CZ])(\d{3})$/', $code, $m) !== 1) {
            return false;
        }

        [, $state, $type, $number] = $m;

        return $type === 'C'
            ? DB::table('counties')->whereRaw('upper(state) = ?', [$state])->whereRaw('right(fips, 3) = ?', [$number])->exists()
            : DB::table('public_zones')->whereRaw('upper(state) = ?', [$state])->where('zone', $number)->exists();
    }
}

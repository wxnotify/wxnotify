# Section-Aware Bulletins and Matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alert rules see every section and every VTEC line of a bulletin, so an event covering a subscriber's zone is matched and delivered even when it is not the bulletin's first section, and one notification per bulletin names every matching event.

**Architecture:** A new `ingested_product_segments` table holds one row per (section × VTEC line) of a VTEC bulletin. `BuildBulletinSegments` runs first in `ProcessIngestedProduct::handle()` and fills it with `ParseBulletinSegments`, which reuses shared parsers (`ParseUgcLine`, `ParseLatLonPolygon`, `ParseVtecString::all()`). `MatchWeatherAlertRules` checks each rule segment by segment (through an unsaved per-segment copy of the bulletin, so the geography code is unchanged) and dispatches one `DeliverWeatherAlert` per rule and channel carrying the matched segment ids; the delivery names every matched event and records the links in `delivery_log_segment`. A bulletin with no segments takes the existing path unchanged.

**Tech Stack:** Laravel app (PHP 8.4), Pest, PostgreSQL/PostGIS, queue (sync in tests).

**Spec:** `docs/superpowers/specs/2026-10-07-bulletin-sections-and-matching-design.md` (read it first; the plan argues from it). Project 2 (`2026-10-07-active-event-zone-coverage-design.md`) builds on the segments this plan creates and is planned separately.

## Global Constraints

- Backend tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest <files>'` (start containers with `docker compose up -d front-end pgsql redis` if stopped). Format the PHP files you changed with `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent <the files you changed>'`; name the files, never use `--dirty` (the owner's unrelated work-in-progress files may be dirty).
- Do not push; commits go on `main`. Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Read `.ai/rules/index.md` and the rule files that match the paths you touch before editing: `pipeline.md` (`app/Actions/Pipeline/**`, `MatchWeatherAlertRules`, the observer), `actions.md`, `models.md`, `jobs.md` (`DeliverWeatherAlert`), `feature.md` (`tests/Feature/**`), `pages.md` (the factory-reset actions).
- `ingested_products` is unchanged: its columns keep being filled from the first section, so the product page, replays, the Test Product Generator, `AppAlert` grouping (rule and bulletin) and every other reader keep working. Non-VTEC products (AFD, forecasts) get no segments.
- A bulletin with no segments (non-VTEC products, an unparsable bulletin, factory-built test rows) must behave exactly as before: the existing tests are the guard and none of them is edited except where a task says so.
- One `DeliverWeatherAlert` per rule and channel and bulletin, carrying the ids of all the rule's matching segments. The one-delivery-per-(rule, channel, bulletin) guard, the SPC/WPC risk-level filter, replay and test-account routing, quiet hours and `is_test` rules are unchanged.
- The event name of a segment is its catalog product's name, else `VtecEvent::name(phenomenon, significance)`, else "Weather alert". The default subject is the unique event names in bulletin order joined with " + "; a rule the subscriber has named keeps its name; `{product}` in a custom or template subject becomes the same joined list.
- CAP messages with a paired text bulletin stay skipped for matching (`SyncActiveWwa::syncFromCap()`, unchanged); a CAP message with no text pair matches through its single segment. `SyncActiveWwa` is not changed by this plan.
- Tests: use factories; `Queue::fake([DeliverWeatherAlert::class])` (not `Queue::fake()`) when the observer's `ProcessIngestedProduct` must really run on the sync queue; `IngestedProduct::withoutEvents(...)`/`createQuietly()` when a test wants no observer; `Http::fake()` for outbound HTTP; Pest helper functions live in the global namespace, so give every helper a name no other test file uses (this plan prefixes them); never name a test property `$this->app`.
- Real bulletins used as fixtures are in `database/product_examples/bulletins/` (`TCV_MOB.txt`, `MWW_MOB.txt`, `NPW_FGZ.txt`, `CFW_JAX.txt`, copied from stored production rows 394223, 395066, 478435 and 480368; the Hurricane Local Statement is trimmed to the same 12 zone sections with shortened narrative). They are committed with this plan and are read-only: never edit them.

## Plan decisions beyond the spec (rulings made while planning; the reviewer should hold the plan to them)

1. **Segment VTEC columns are nullable.** A fallback segment is built from the bulletin's own columns, and factory-built rows in the existing tests set only some of them (`vtec_office` alone, for example). Only `ingested_product_id` and `position` are required.
2. **`ParseVtecString::all()` is added; `__invoke()` returns `all()[0]`.** The year semantics (`now()->year`) are unchanged.
3. **The matcher works on an unsaved copy.** `IngestedProduct::asSegment($segment)` returns an in-memory copy of the bulletin that carries the segment's `product_id`, `ugc_codes`, `polygon` and `vtec_*`. Every existing geography method keeps taking an `IngestedProduct`, so none of them changes. The copy is never saved.
4. **`RenderAlertSubject`'s second argument becomes `Product|array|null`.** An array is a list of event names; a `Product` or null behaves exactly as before, so every existing caller and test is untouched.
5. **A rule's matched location is the matching location with the lowest id across all its matching segments** (the spec's "first matching location by id"), not the location of the first matching segment.
6. **Segment ids that no longer exist when a delivery runs are ignored** (a rebuild may have replaced them between dispatch and run); the delivery links only the segments it can still load.
7. **`WipePipelineData` and `PreviewFactoryReset` return an extra `ingested_product_segments` count** (between `ingested_products` and `active_wwas`) and truncate both new tables. The reset page's summary line is not changed.
8. **The build runs inside `try/catch` in the job.** A segment-building failure is logged and the bulletin is processed without segments (the old path), never failed; the build parses before it deletes, so a parse failure leaves the old segments in place.
9. **A bulletin's own `ugc_codes` is now read by `ParseUgcLine`, so for roughly 4% of bulletins it changes.** The old reader skipped a first zone block it could not read and returned the next one that it could (a Jacksonville flood watch listed Georgia's two zones instead of its own seventeen Florida zones). The new value is the correct one and no existing test depends on the old behavior.

## Validation done while planning

The code in this plan was applied on a scratch branch (since discarded) and its tests passed, together with the whole suite (2,613 tests with project 2). A read-only dry run of `ParseBulletinSegments` over the 967 VTEC text bulletins stored in the last three days gave: every bulletin yields segments (3,513 in all); 432 (45%) have more than one; none is left without zones (8.5% were before); and 42 differ from the old reader in their first zone block, all in the way decision 9 describes. The new tests were also run against the unchanged matcher, reader and job code and failed there, so they guard the change.

## File Structure

Create:
- `app/Actions/Pipeline/ParseUgcLine.php`, `ParseLatLonPolygon.php`, `ParseBulletinSegments.php`, `BuildBulletinSegments.php`
- `app/Models/IngestedProductSegment.php`, `database/factories/IngestedProductSegmentFactory.php`
- `database/migrations/2026_10_07_100001_create_ingested_product_segments_table.php`, `2026_10_07_100002_create_delivery_log_segment_table.php`
- `app/Console/Commands/ParseStoredBulletinSegments.php` (`bulletins:parse-segments`)
- Tests (`tests/Feature/`): `ParseUgcLineTest`, `ParseLatLonPolygonTest`, `IngestedProductSegmentTest`, `ParseBulletinSegmentsTest`, `BuildBulletinSegmentsTest`, `ParseStoredBulletinSegmentsTest`, `BulletinSegmentMatchingTest`

Modify:
- `app/Actions/Pipeline/ParseVtecString.php` (`all()`), `ExtractProductMetadata.php` (use the shared parsers)
- `app/Models/IngestedProduct.php` (`segments()`, `asSegment()`, `matchedSegments()`, `eventNamesFor()`), `DeliveryLog.php` (`segments()`), `ActiveWwa.php` (`vtecKeyFor()` accepts a segment, `scopeForSegment()`)
- `app/Actions/Admin/FactoryReset/WipePipelineData.php`, `PreviewFactoryReset.php`
- `app/Jobs/ProcessIngestedProduct.php`, `app/Jobs/DeliverWeatherAlert.php`
- `app/Actions/Weather/RenderAlertSubject.php`, `ComposeTemplatedAlertBody.php`, `MatchWeatherAlertRules.php`
- `app/Actions/Alerts/GetActiveNowAtMyLocations.php`
- `.ai/rules/pipeline.md`
- Tests: `ParseVtecStringTest`, `ExtractProductMetadataTest`, `FactoryResetActionsTest`, `ProcessIngestedProductTest`, `RenderAlertSubjectTest`, `DeliverWeatherAlertTest`, `ComposeTemplatedAlertBodyTest`, `GetActiveNowAtMyLocationsTest`

---

### Task 1: Shared parsers (UGC grammar fix, polygon, all VTEC lines)

**Files:**
- Create: `app/Actions/Pipeline/ParseUgcLine.php`, `app/Actions/Pipeline/ParseLatLonPolygon.php`
- Modify: `app/Actions/Pipeline/ParseVtecString.php`, `app/Actions/Pipeline/ExtractProductMetadata.php`
- Test: `tests/Feature/ParseUgcLineTest.php` (create), `tests/Feature/ParseLatLonPolygonTest.php` (create), `tests/Feature/ParseVtecStringTest.php`, `tests/Feature/ExtractProductMetadataTest.php`

**Interfaces:**
- Produces: `(new ParseUgcLine)(string $text): ?list<string>` (the codes of the first zone block in `$text`, null when there is none); `(new ParseLatLonPolygon)(string $text): ?array<int, array{lat: float, lon: float}>` plus public statics `ParseLatLonPolygon::decodeCombinedToken(string): array{lat: float, lon: float}` and `::decodeCoordinatePart(string): float`; `(new ParseVtecString)->all(string $text): list<array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: ?Carbon}>` (every VTEC string in order; `__invoke` keeps returning the first or null).
- Consumes: nothing from earlier tasks.

The zone-line grammar today accepts a range only on a bare number (`070>075`), so a range on a full code (`CAZ110>114`, standard NWS form) makes the whole line unreadable: 63 of 737 stored VTEC bulletins (8.5%) yield no zones at all. The new reader also handles a zone line wrapped across lines and a prefix that changes inside the line (`MSZ086-LAZ070-076-`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ParseUgcLineTest.php`:

```php
<?php

use App\Actions\Pipeline\ParseUgcLine;

test('reads the first zone line of a section', function (string $text, ?array $expected) {
    expect((new ParseUgcLine)($text))->toBe($expected);
})->with([
    'bare numbers after a full code' => ["GMZ650-655-670-675-080000-\n/O.NEW.KMOB.HU.A.1009.261007T1538Z-000000T0000Z/", ['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675']],
    'a range after a bare number' => ["NYZ069-070>075-081200-\n", ['NYZ069', 'NYZ070', 'NYZ071', 'NYZ072', 'NYZ073', 'NYZ074', 'NYZ075']],
    'a range after a full code' => ["GMZ630>636-080000-\n", ['GMZ630', 'GMZ631', 'GMZ632', 'GMZ633', 'GMZ634', 'GMZ635', 'GMZ636']],
    'a full-code range followed by a bare range and a bare number' => ["CAZ110>114-116>118-120-101900-\n", ['CAZ110', 'CAZ111', 'CAZ112', 'CAZ113', 'CAZ114', 'CAZ116', 'CAZ117', 'CAZ118', 'CAZ120']],
    'a zone line wrapped across lines' => ["FLZ201-202-203-\n204>206-080015-\n", ['FLZ201', 'FLZ202', 'FLZ203', 'FLZ204', 'FLZ205', 'FLZ206']],
    'a prefix that changes inside the line' => ["MSZ086-LAZ070-076-111200-\n", ['MSZ086', 'LAZ070', 'LAZ076']],
    'counties and zones together' => ["NYC061-NJZ103-081200-\n", ['NYC061', 'NJZ103']],
    'windows line endings' => ["NYZ069-070-081200-\r\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\r\n", ['NYZ069', 'NYZ070']],
    'only the first block of the text' => ["ALZ266-080015-\nBaldwin Coastal-\n\n\$\$\n\nALZ265-080015-\n", ['ALZ266']],
    'a code repeated in the line is listed once' => ["FLZ201-201-080015-\n", ['FLZ201']],
    'a name line ending in a dash is not a zone line' => ["Baldwin Coastal-\nMobile Coastal-\n", null],
    'a zone line with no purge time' => ["FLZ201-202-\nBaldwin Coastal-\n", null],
    'text with no zone line' => ["AREA FORECAST DISCUSSION\nNo UGC line here.", null],
]);

test('reads the real bulletins that used to yield no zones', function (string $example, int $count, string $first, string $last) {
    $text = file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt"));

    $codes = (new ParseUgcLine)($text);

    expect($codes)->toHaveCount($count)
        ->and($codes[0])->toBe($first)
        ->and(end($codes))->toBe($last);
})->with([
    'a Non-Precipitation Weather bulletin (AZZ004>018-037>040)' => ['NPW_FGZ', 19, 'AZZ004', 'AZZ040'],
    'the first section of a Marine Weather Message' => ['MWW_MOB', 4, 'GMZ650', 'GMZ675'],
    'the first section of a Hurricane Local Statement' => ['TCV_MOB', 1, 'ALZ266', 'ALZ266'],
]);
```

Create `tests/Feature/ParseLatLonPolygonTest.php`:

```php
<?php

use App\Actions\Pipeline\ParseLatLonPolygon;

test('decodes a warning-style polygon with a wrapped continuation line', function () {
    $text = "LAT...LON 4031 10526 4040 10501 4012 10490\n      3995 10510\nTIME...MOT...LOC 1805Z 240DEG 25KT 4020 10500\n";

    $points = (new ParseLatLonPolygon)($text);

    expect($points)->toHaveCount(4)
        ->and($points[0])->toEqual(['lat' => 40.31, 'lon' => -105.26])
        ->and($points[3])->toEqual(['lat' => 39.95, 'lon' => -105.10]);
});

test('decodes a combined-token polygon, restoring a dropped leading 1 on a longitude of 100 or more', function () {
    $points = (new ParseLatLonPolygon)("LAT...LON 33311429 33591398 34281370\n\n");

    expect($points)->toHaveCount(3)
        ->and($points[0])->toEqual(['lat' => 33.31, 'lon' => -114.29]);
});

test('returns null without a LAT...LON line, and without enough coordinates', function () {
    expect((new ParseLatLonPolygon)("No polygon here.\n"))->toBeNull()
        ->and((new ParseLatLonPolygon)("LAT...LON 4031 10526 4040 10501\n\n"))->toBeNull();
});
```

Append to `tests/Feature/ParseVtecStringTest.php`:

```php
test('all() returns every VTEC string in the text, in order', function () {
    Carbon::setTestNow('2026-10-07T12:00:00Z');
    $text = "GMZ650-655-080000-\n/O.NEW.KMOB.HU.A.1009.261007T1538Z-000000T0000Z/\n/O.CON.KMOB.SC.Y.0031.000000T0000Z-261009T0800Z/\n";

    $all = (new ParseVtecString)->all($text);

    expect($all)->toHaveCount(2)
        ->and($all[0]['phenomenon'])->toBe('HU')
        ->and($all[0]['action'])->toBe('NEW')
        ->and($all[0]['begin'])->toEqual(Carbon::parse('2026-10-07T15:38:00Z'))
        ->and($all[0]['end'])->toBeNull()
        ->and($all[1]['phenomenon'])->toBe('SC')
        ->and($all[1]['significance'])->toBe('Y')
        ->and($all[1]['event_number'])->toBe('0031')
        ->and($all[1]['begin'])->toBeNull()
        ->and($all[1]['end'])->toEqual(Carbon::parse('2026-10-09T08:00:00Z'));
});

test('all() is empty without a VTEC string, and the first one is still what __invoke returns', function () {
    $text = "/O.NEW.KMOB.HU.A.1009.261007T1538Z-000000T0000Z/\n/O.CON.KMOB.SC.Y.0031.000000T0000Z-261009T0800Z/\n";

    expect((new ParseVtecString)->all('No VTEC here.'))->toBe([])
        ->and((new ParseVtecString)($text)['phenomenon'])->toBe('HU')
        ->and((new ParseVtecString)('No VTEC here.'))->toBeNull();
});
```

Append to `tests/Feature/ExtractProductMetadataTest.php` (the file already imports what it needs; keep its existing imports):

```php
test('a UGC range that starts on a full code expands, so the real bulletins that yielded no zones now do', function (string $example, int $count, string $first) {
    $text = file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt"));

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toHaveCount($count)
        ->and($metadata['ugc_codes'][0])->toBe($first);
})->with([
    'a Non-Precipitation Weather bulletin (AZZ004>018-037>040)' => ['NPW_FGZ', 19, 'AZZ004'],
    'a Marine Weather Message (first section)' => ['MWW_MOB', 4, 'GMZ650'],
]);

test('a full-code range in the first zone line of a text product reaches ugc_codes', function () {
    $metadata = (new ExtractProductMetadata)("GMZ630>636-080000-\n/O.NEW.KMOB.HU.A.1009.261007T1538Z-000000T0000Z/\n", null);

    expect($metadata['ugc_codes'])->toBe(['GMZ630', 'GMZ631', 'GMZ632', 'GMZ633', 'GMZ634', 'GMZ635', 'GMZ636']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ParseUgcLineTest.php tests/Feature/ParseLatLonPolygonTest.php tests/Feature/ParseVtecStringTest.php tests/Feature/ExtractProductMetadataTest.php'`
Expected: FAIL (classes `ParseUgcLine` and `ParseLatLonPolygon` not found, `ParseVtecString::all()` undefined, and the two full-code range tests in `ExtractProductMetadataTest` get null zones).

- [ ] **Step 3: Create `ParseUgcLine`**

Create `app/Actions/Pipeline/ParseUgcLine.php`:

```php
<?php

namespace App\Actions\Pipeline;

/**
 * Reads the first UGC block of a product (or of one section of it): the dash-separated zone and
 * county codes that sit just above the VTEC line and end in a 6-digit DDHHMM purge time, e.g.
 * "NYZ069-070>075-081200-".
 *
 * A bare 3-digit token continues the most recent state+type prefix; a range ("070>075") expands
 * to every code between its ends and may follow a bare number or a full code ("CAZ110>114"); the
 * prefix may change inside the line ("MSZ086-LAZ070-076-"); and a long list wraps onto further
 * lines, each ending in a dash, until the line that carries the purge time. A line that merely
 * looks like one ("Baldwin Coastal-", the zone names that follow) is never a zone line because it
 * contains lowercase letters or spaces.
 */
class ParseUgcLine
{
    private const BLOCK_START = '/^[A-Z]{2}[CZ]\d{3}(?:>\d{3})?-/';

    private const BLOCK_LINE = '/^[A-Z0-9>-]+-$/';

    private const BLOCK = '/^(?:[A-Z]{2}[CZ]\d{3}|\d{3})(?:>\d{3})?(?:-(?:[A-Z]{2}[CZ]\d{3}|\d{3})(?:>\d{3})?)*-\d{6}-$/';

    /**
     * @return ?list<string> each code once, in the order written; null when the text has no zone block
     */
    public function __invoke(string $text): ?array
    {
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', $text));

        foreach ($lines as $start => $line) {
            if (! preg_match(self::BLOCK_START, $line)) {
                continue;
            }

            $block = $this->collectBlock($lines, $start);

            if ($block !== null) {
                return $this->expand($block);
            }
        }

        return null;
    }

    /**
     * Joins the lines from $start up to the one carrying the purge time. Null when a line in
     * between is not a zone line, or the text ends first, or the joined block is not valid.
     *
     * @param  list<string>  $lines
     */
    private function collectBlock(array $lines, int $start): ?string
    {
        $block = '';

        for ($i = $start; $i < count($lines); $i++) {
            if (! preg_match(self::BLOCK_LINE, $lines[$i])) {
                return null;
            }

            $block .= $lines[$i];

            if (preg_match('/-\d{6}-$/', $block)) {
                return preg_match(self::BLOCK, $block) ? $block : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function expand(string $block): array
    {
        $codes = [];
        $prefix = null;

        foreach (explode('-', substr($block, 0, -8)) as $token) {
            if (! preg_match('/^(?:([A-Z]{2}[CZ])(\d{3})|(\d{3}))(?:>(\d{3}))?$/', $token, $m)) {
                continue;
            }

            if ($m[1] !== '') {
                $prefix = $m[1];
            }

            $first = (int) ($m[1] !== '' ? $m[2] : $m[3]);
            $last = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $first;

            foreach (range($first, $last) as $number) {
                $codes[] = $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($codes));
    }
}
```

- [ ] **Step 4: Create `ParseLatLonPolygon` by moving the polygon code out of `ExtractProductMetadata`**

Create `app/Actions/Pipeline/ParseLatLonPolygon.php` (the body is `ExtractProductMetadata::parsePolygon()`, `decodeCombinedToken()` and `decodeCoordinatePart()` moved verbatim, now public so the categorical-outlook code can keep using the token decoder):

```php
<?php

namespace App\Actions\Pipeline;

/**
 * Decodes the "LAT...LON" polygon of a product's raw text (or of one section of it).
 */
class ParseLatLonPolygon
{
    /**
     * Finds a "LAT...LON" line, collects wrapped continuation lines until a
     * blank line or a "TIME..." line, and decodes each coordinate token.
     * Doesn't pre-filter by product type (the legacy reference implementation
     * gates this on a fixed list of AWIPS product types) — scanning every
     * product's text for the line and returning null when absent is simpler
     * and has the same practical effect, since most VTEC products never
     * carry one.
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    public function __invoke(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $collecting = false;
        $tokens = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (! $collecting) {
                if (! str_starts_with($trimmed, 'LAT...LON')) {
                    continue;
                }

                $collecting = true;
                $trimmed = trim(substr($trimmed, strlen('LAT...LON')));
            } elseif ($trimmed === '' || str_starts_with($trimmed, 'TIME...')) {
                break;
            } elseif (! preg_match('/^\d/', $trimmed)) {
                // Mirrors the legacy reference's ^\s*\d+ gate on continuation
                // lines: a non-digit-leading line (e.g. stray remarks text
                // before TIME...) is skipped, not appended -- otherwise a
                // preg_match_all() scanning the whole line could splice
                // unrelated digits into the polygon instead of failing safe.
                continue;
            }

            if (preg_match_all('/\d{4,9}/', $trimmed, $matches)) {
                array_push($tokens, ...$matches[0]);
            }
        }

        if ($tokens === []) {
            return null;
        }

        // SPC MD/WPC MPD products encode each lat/lon pair as one
        // concatenated 8-9 digit token (e.g. "33311429") rather than
        // warning-family's two separate space-delimited 4-5 digit tokens
        // (e.g. "3650 9082") -- confirmed against this repo's own real SPC
        // MCD fixture, database/product_examples/SWO.txt. A product uses
        // one convention consistently, so the presence of any combined
        // token switches parsing for the whole line.
        $isCombinedFormat = collect($tokens)->contains(fn (string $token): bool => strlen($token) >= 8);

        if ($isCombinedFormat) {
            $points = collect($tokens)->map(fn (string $token): array => self::decodeCombinedToken($token))->all();

            return count($points) >= 3 ? $points : null;
        }

        if (count($tokens) < 6 || count($tokens) % 2 !== 0) {
            return null;
        }

        return collect(array_chunk($tokens, 2))
            ->map(fn (array $pair): array => [
                'lat' => self::decodeCoordinatePart($pair[0]),
                'lon' => -self::decodeCoordinatePart($pair[1]),
            ])
            ->all();
    }

    /**
     * Decodes one combined lat/lon token: the first 4 digits are latitude
     * (decimal after 2, as usual); the remaining 4 digits are longitude,
     * ALWAYS read directly (decimal after 2) -- never with a leading "1"
     * blindly prepended. That prior rule was reverse-engineered from a
     * single fixture (database/product_examples/SWO.txt's real Arizona MD,
     * token "33311429") where the true longitude genuinely is >= 100
     * degrees (114.29), and prepending "1" happened to fix that one case.
     * It silently breaks for a longitude < 100 -- confirmed against a real
     * PTSDY1 (SPC Day 1 Outlook Areal Outline) bulletin's own categorical
     * risk-area coordinates: token "32959919" decodes correctly, directly,
     * to (32.95, -99.19) -- almost exactly between Abilene and Wichita
     * Falls, TX, matching that same bulletin's own "...TO THE RIGHT OF A
     * LINE FROM 45 NE ABI 65 WSW SPS..." text -- while prepending "1"
     * produces an impossible -199.19.
     *
     * The actual rule: decode the 4-digit remainder directly; if that
     * yields a magnitude under 60 (no real CONUS longitude is ever that
     * small -- Maine, the easternmost point, is ~67), the source must have
     * dropped a leading "1" digit from a true value >= 100, so restore it.
     * A genuine < 100 value (the common case east of the Rockies -- the
     * Great Plains/Midwest/South are almost entirely in the 90s) and a
     * genuine >= 100 value transmitted this way (114.29 minus its dropped
     * leading digit lands at 14.29) can never collide in that gap; both
     * real fixtures above decode correctly, ring-wide, against this rule.
     *
     * @return array{lat: float, lon: float}
     */
    public static function decodeCombinedToken(string $token): array
    {
        $lat = substr($token, 0, 4);
        $lonDirect = self::decodeCoordinatePart(substr($token, 4));
        $lon = $lonDirect < 60 ? self::decodeCoordinatePart('1'.substr($token, 4)) : $lonDirect;

        return [
            'lat' => self::decodeCoordinatePart($lat),
            'lon' => -$lon,
        ];
    }

    /**
     * Mirrors App\Libraries\AwipsProduct::getAffectedAreaCoords()'s real
     * decode math: a 4-digit value inserts the decimal after 2 digits
     * (e.g. "3982" -> 39.82), a 5-digit value inserts it after 3 (e.g.
     * "10066" -> 100.66 -- needed for longitudes >= 100 degrees).
     */
    public static function decodeCoordinatePart(string $raw): float
    {
        return strlen($raw) === 5
            ? (float) (substr($raw, 0, 3).'.'.substr($raw, 3))
            : (float) (substr($raw, 0, 2).'.'.substr($raw, 2));
    }
}
```

- [ ] **Step 5: Add `ParseVtecString::all()`**

Replace the `__invoke` method of `app/Actions/Pipeline/ParseVtecString.php` (the class docblock and `PATTERN` stay) with:

```php
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
```

- [ ] **Step 6: Make `ExtractProductMetadata` use the shared parsers**

In `app/Actions/Pipeline/ExtractProductMetadata.php`:

1. In `__invoke()`, replace `'ugc_codes' => $this->parseUgcCodes($text),` with `'ugc_codes' => (new ParseUgcLine)($text),` and replace `'polygon' => $isCategoricalAreal ? null : $this->parsePolygon($text),` with `'polygon' => $isCategoricalAreal ? null : (new ParseLatLonPolygon)($text),`.
2. In `extractCategoricalRiskAreas()`, replace `array_map(self::decodeCombinedToken(...), $matches[0])` with `array_map(ParseLatLonPolygon::decodeCombinedToken(...), $matches[0])`.
3. Delete these four methods and their docblocks from the class: `parseUgcCodes()`, `parsePolygon()`, `decodeCombinedToken()`, `decodeCoordinatePart()` (everything from the `Standard NWS UGC line` docblock to the end of `parseUgcCodes()`, and everything from the `Finds a "LAT...LON" line` docblock to the end of the class except the closing brace). Nothing else in the class changes.
4. Update the class docblock's first sentence is already accurate; add nothing.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ParseUgcLineTest.php tests/Feature/ParseLatLonPolygonTest.php tests/Feature/ParseVtecStringTest.php tests/Feature/ExtractProductMetadataTest.php tests/Feature/IngestProductTextTest.php tests/Feature/CapIngestionIntegrationTest.php'`
Expected: PASS (every existing UGC and polygon test in `ExtractProductMetadataTest` still passes through the moved code).

- [ ] **Step 8: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Pipeline/ParseUgcLine.php app/Actions/Pipeline/ParseLatLonPolygon.php app/Actions/Pipeline/ParseVtecString.php app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ParseUgcLineTest.php tests/Feature/ParseLatLonPolygonTest.php tests/Feature/ParseVtecStringTest.php tests/Feature/ExtractProductMetadataTest.php'
git add app/Actions/Pipeline tests/Feature/ParseUgcLineTest.php tests/Feature/ParseLatLonPolygonTest.php tests/Feature/ParseVtecStringTest.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "Pipeline: read a UGC range that starts on a full code, wrapped zone lines and every VTEC line

63 of 737 stored VTEC bulletins yielded no zones because a range like CAZ110>114 was
not understood. The zone, polygon and VTEC readers are now shared classes.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Segment tables, models and the factory-reset wipe

**Files:**
- Create: `database/migrations/2026_10_07_100001_create_ingested_product_segments_table.php`, `database/migrations/2026_10_07_100002_create_delivery_log_segment_table.php`, `app/Models/IngestedProductSegment.php`, `database/factories/IngestedProductSegmentFactory.php`
- Modify: `app/Models/IngestedProduct.php`, `app/Models/DeliveryLog.php`, `app/Models/ActiveWwa.php`, `app/Actions/Admin/FactoryReset/WipePipelineData.php`, `app/Actions/Admin/FactoryReset/PreviewFactoryReset.php`
- Test: `tests/Feature/IngestedProductSegmentTest.php` (create), `tests/Feature/FactoryResetActionsTest.php`

**Interfaces:**
- Produces: tables `ingested_product_segments` and `delivery_log_segment`; `IngestedProductSegment` (fillable `position, vtec_office, vtec_phenomenon, vtec_significance, vtec_event_number, vtec_year, vtec_action, vtec_begin, vtec_end, product_id, ugc_codes, polygon`; casts `ugc_codes`/`polygon` array, `vtec_begin`/`vtec_end` datetime; relations `ingestedProduct()`, `product()`, `deliveryLogs()`; `eventName(): string`; factory with state `forCatalogCode(string $code)`); `IngestedProduct::segments(): HasMany` (ordered by `position`), `asSegment(IngestedProductSegment): static`, `matchedSegments(array $segmentIds): Collection`, `eventNamesFor(Collection $segments): array<int, string>`; `DeliveryLog::segments(): BelongsToMany`; `ActiveWwa::vtecKeyFor()` accepting a segment and `ActiveWwa::scopeForSegment(Builder, IngestedProductSegment)`; the wipe/preview arrays gain `ingested_product_segments`.
- Consumes: nothing from earlier tasks.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/IngestedProductSegmentTest.php`:

```php
<?php

use App\Models\ActiveWwa;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;

/** A bulletin made without the observer, so no job builds segments behind the test's back. */
function segmentTestBulletin(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('a bulletin lists its segments in position order whatever order they were saved in', function () {
    $bulletin = segmentTestBulletin();
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 2, 'vtec_event_number' => '0003']);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 0, 'vtec_event_number' => '0001']);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 1, 'vtec_event_number' => '0002']);

    expect($bulletin->segments->pluck('vtec_event_number')->all())->toBe(['0001', '0002', '0003']);
});

test('a segment is named by its catalog product, else by its VTEC codes, else generically', function () {
    $catalogued = IngestedProductSegment::factory()->forCatalogCode('HU.A')->create();
    // The catalog is a curated subset: it has no Rip Current Statement (RP.S).
    expect(Product::where('code', 'RP.S')->exists())->toBeFalse();
    $uncatalogued = IngestedProductSegment::factory()->create(['vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'product_id' => null]);
    $unknown = IngestedProductSegment::factory()->create(['vtec_phenomenon' => 'XX', 'vtec_significance' => 'Q', 'product_id' => null]);

    expect($catalogued->eventName())->toBe('Hurricane Watch')
        ->and($uncatalogued->eventName())->toBe('Rip Current Statement')
        ->and($unknown->eventName())->toBe('Weather alert');
});

test('deleting a bulletin deletes its segments, and deleting a segment unlinks its deliveries', function () {
    $bulletin = segmentTestBulletin();
    $segment = IngestedProductSegment::factory()->for($bulletin)->create();
    $log = DeliveryLog::factory()->create();
    $log->segments()->attach($segment);

    expect($log->segments()->count())->toBe(1);

    $bulletin->delete();

    expect(IngestedProductSegment::count())->toBe(0)
        ->and($log->segments()->count())->toBe(0)
        ->and(DeliveryLog::count())->toBe(1);
});

test('asSegment gives an unsaved copy of the bulletin that carries the segment\'s identity', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = segmentTestBulletin([
        'cccc' => 'kmob', 'is_test' => false, 'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'SS', 'vtec_significance' => 'A',
        'vtec_event_number' => '1009', 'vtec_year' => 2026, 'ugc_codes' => ['ALZ266'], 'polygon' => null, 'product_id' => null,
    ]);
    $segment = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create([
        'vtec_office' => 'KMOB', 'vtec_event_number' => '1009', 'vtec_year' => 2026, 'ugc_codes' => ['FLZ206'],
        'polygon' => [['lat' => 30.0, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.5]],
    ]);

    $view = $bulletin->asSegment($segment);

    expect($view->exists)->toBeFalse()
        ->and($view->id)->toBe($bulletin->id)
        ->and($view->cccc)->toBe('kmob')
        ->and($view->product_id)->toBe($hurricaneWatch->id)
        ->and($view->product->code)->toBe('HU.A')
        ->and($view->ugc_codes)->toBe(['FLZ206'])
        ->and($view->polygon)->toHaveCount(3)
        ->and($view->vtec_phenomenon)->toBe('HU')
        ->and($view->vtec_significance)->toBe('A')
        ->and($bulletin->fresh()->ugc_codes)->toBe(['ALZ266'])
        ->and($bulletin->fresh()->vtec_phenomenon)->toBe('SS');
});

test('matchedSegments loads only the listed segments that still exist, in position order, with their products', function () {
    $bulletin = segmentTestBulletin();
    $second = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('SC.Y')->create(['position' => 1]);
    $first = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0]);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 2]);

    $segments = $bulletin->matchedSegments([$second->id, $first->id, 999999]);

    expect($segments->modelKeys())->toBe([$first->id, $second->id])
        ->and($segments->first()->relationLoaded('product'))->toBeTrue()
        ->and($bulletin->matchedSegments([])->isEmpty())->toBeTrue();
});

test('event names are unique, in bulletin order, and fall back to the bulletin\'s own catalog product without segments', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = segmentTestBulletin(['product_id' => $hurricaneWatch->id]);
    $watchOne = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0]);
    $advisory = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('SC.Y')->create(['position' => 1]);
    $watchTwo = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 2]);

    expect($bulletin->eventNamesFor($bulletin->matchedSegments([$watchOne->id, $advisory->id, $watchTwo->id])))->toBe(['Hurricane Watch', 'Small Craft Advisory'])
        ->and($bulletin->eventNamesFor($bulletin->matchedSegments([])))->toBe(['Hurricane Watch'])
        ->and(segmentTestBulletin(['product_id' => null])->eventNamesFor(collect()))->toBe([]);
});

test('forSegment finds the active event of a segment\'s VTEC key, scoped to its bulletin\'s is_test flag', function () {
    $real = ActiveWwa::factory()->create(['vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026, 'is_test' => false]);
    ActiveWwa::factory()->create(['vtec_office' => 'KMOB', 'vtec_phenomenon' => 'SC', 'vtec_significance' => 'Y', 'vtec_event_number' => '0031', 'vtec_year' => 2026, 'is_test' => false]);
    $segment = IngestedProductSegment::factory()->for(segmentTestBulletin(['is_test' => false]))->create([
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
    ]);
    $testSegment = IngestedProductSegment::factory()->for(segmentTestBulletin(['is_test' => true]))->create([
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
    ]);

    expect(ActiveWwa::query()->forSegment($segment)->sole()->id)->toBe($real->id)
        ->and(ActiveWwa::query()->forSegment($testSegment)->count())->toBe(0);
});
```

In `tests/Feature/FactoryResetActionsTest.php`:

1. Add `use App\Models\IngestedProductSegment;` with the file's other model imports.
2. Replace `frSeedPipelineData()` with:

```php
function frSeedPipelineData(): void
{
    $ingested = IngestedProduct::factory()->count(3)->create();
    $segment = IngestedProductSegment::factory()->for($ingested->first())->create();
    ActiveWwa::factory()->create(['latest_ingested_product_id' => $ingested->first()->id]);
    DeliveryLog::factory()->count(2)->create()->first()->segments()->attach($segment);
    TestProductScenario::factory()->create();
}
```
3. In the wipe test replace `expect($counts)->toBe(['ingested_products' => 3, 'active_wwas' => 1, 'delivery_logs' => 2])` with `expect($counts)->toBe(['ingested_products' => 3, 'ingested_product_segments' => 1, 'active_wwas' => 1, 'delivery_logs' => 2])` and add `->and(IngestedProductSegment::count())->toBe(0)->and(DB::table('delivery_log_segment')->count())->toBe(0)` to that chain (add `use Illuminate\Support\Facades\DB;` if the file does not import it).
4. In the preview test replace `expect($preview['pipeline'])->toBe(['ingested_products' => 3, 'active_wwas' => 1, 'delivery_logs' => 2])` with `expect($preview['pipeline'])->toBe(['ingested_products' => 3, 'ingested_product_segments' => 1, 'active_wwas' => 1, 'delivery_logs' => 2])`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/IngestedProductSegmentTest.php tests/Feature/FactoryResetActionsTest.php'`
Expected: FAIL (`IngestedProductSegment` class not found).

- [ ] **Step 3: Create the migrations**

Create `database/migrations/2026_10_07_100001_create_ingested_product_segments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_product_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingested_product_id')->constrained()->cascadeOnDelete();
            // Bulletin order: sections top to bottom, and within a section its VTEC lines top to bottom.
            $table->unsignedSmallInteger('position');
            // The same five columns as ActiveWwa::vtecKeyFor(), plus the action and times. Nullable: a
            // fallback segment copies a bulletin's own columns, which can be partly empty.
            $table->string('vtec_office')->nullable();
            $table->string('vtec_phenomenon')->nullable();
            $table->string('vtec_significance')->nullable();
            $table->string('vtec_event_number', 4)->nullable();
            $table->smallInteger('vtec_year')->nullable();
            $table->string('vtec_action')->nullable();
            $table->timestamp('vtec_begin')->nullable();
            $table->timestamp('vtec_end')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('ugc_codes')->nullable();
            $table->json('polygon')->nullable();
            $table->timestamps();

            $table->index(['ingested_product_id', 'position']);
            $table->index(
                ['vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year'],
                'ingested_product_segments_vtec_key_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_product_segments');
    }
};
```

Create `database/migrations/2026_10_07_100002_create_delivery_log_segment_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which events of a bulletin a delivery covered.
        Schema::create('delivery_log_segment', function (Blueprint $table) {
            $table->foreignId('delivery_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingested_product_segment_id')->constrained()->cascadeOnDelete();

            $table->primary(['delivery_log_id', 'ingested_product_segment_id']);
            $table->index('ingested_product_segment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_log_segment');
    }
};
```

- [ ] **Step 4: Create the model and factory**

Create `app/Models/IngestedProductSegment.php`:

```php
<?php

namespace App\Models;

use App\Support\VtecEvent;
use Database\Factories\IngestedProductSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One event of one section of a VTEC bulletin: a section with two VTEC lines gives two segments
 * that share the section's zones and polygon. Built by App\Actions\Pipeline\BuildBulletinSegments
 * and matched by MatchWeatherAlertRules, which is how a rule sees an event that is not the
 * bulletin's first section or first VTEC line.
 */
#[Fillable([
    'position', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end', 'product_id', 'ugc_codes', 'polygon',
])]
class IngestedProductSegment extends Model
{
    /** @use HasFactory<IngestedProductSegmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ugc_codes' => 'array',
            'polygon' => 'array',
            'vtec_begin' => 'datetime',
            'vtec_end' => 'datetime',
        ];
    }

    public function ingestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveryLogs(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryLog::class, 'delivery_log_segment');
    }

    /**
     * What to call this event: its catalog product's name, else the NWS name its VTEC codes give
     * (the catalog is a curated subset), else a generic label.
     */
    public function eventName(): string
    {
        return $this->product?->name
            ?? VtecEvent::name((string) $this->vtec_phenomenon, (string) $this->vtec_significance)
            ?? 'Weather alert';
    }
}
```

Create `database/factories/IngestedProductSegmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestedProductSegment>
 */
class IngestedProductSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ingested_product_id' => IngestedProduct::factory(),
            'position' => 0,
            'vtec_office' => 'KMOB',
            'vtec_phenomenon' => 'HU',
            'vtec_significance' => 'A',
            'vtec_event_number' => '1009',
            'vtec_year' => 2026,
            'vtec_action' => 'NEW',
            'ugc_codes' => ['FLZ206'],
        ];
    }

    /**
     * A real catalog event ("HU.A"): sets the VTEC phenomenon and significance and links the catalog product.
     */
    public function forCatalogCode(string $code): static
    {
        return $this->state(fn (): array => [
            'vtec_phenomenon' => substr($code, 0, 2),
            'vtec_significance' => substr($code, 3, 1),
            'product_id' => Product::where('code', $code)->value('id'),
        ]);
    }
}
```

- [ ] **Step 5: Add the relations and helpers to the existing models**

In `app/Models/IngestedProduct.php` add these imports with the others: `use Illuminate\Database\Eloquent\Collection as EloquentCollection;` and `use Illuminate\Support\Collection;`. Add these methods after `replays()`:

```php
    /**
     * The bulletin's events, one per (section x VTEC line), in bulletin order. Empty for a
     * non-VTEC product and for a bulletin stored before segments existed.
     *
     * @return HasMany<IngestedProductSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(IngestedProductSegment::class)->orderBy('position');
    }

    /**
     * An unsaved, in-memory copy of this bulletin that carries one segment's identity: its catalog
     * product, zones, polygon and VTEC fields. The geography matchers read those from an
     * IngestedProduct, so matching a segment is matching this copy. Never save it.
     */
    public function asSegment(IngestedProductSegment $segment): static
    {
        $view = new static;
        $view->setRawAttributes($this->getAttributes());
        $view->forceFill([
            'product_id' => $segment->product_id,
            'ugc_codes' => $segment->ugc_codes,
            'polygon' => $segment->polygon,
            'vtec_office' => $segment->vtec_office,
            'vtec_phenomenon' => $segment->vtec_phenomenon,
            'vtec_significance' => $segment->vtec_significance,
            'vtec_event_number' => $segment->vtec_event_number,
            'vtec_year' => $segment->vtec_year,
            'vtec_action' => $segment->vtec_action,
            'vtec_begin' => $segment->vtec_begin,
            'vtec_end' => $segment->vtec_end,
        ]);

        return $view;
    }

    /**
     * The listed segments of this bulletin that still exist, in bulletin order, with their catalog
     * product loaded. An id that is gone (a rebuild replaced the segments) is ignored.
     *
     * @param  list<int>  $segmentIds
     * @return EloquentCollection<int, IngestedProductSegment>
     */
    public function matchedSegments(array $segmentIds): EloquentCollection
    {
        if ($segmentIds === []) {
            return new EloquentCollection;
        }

        return $this->segments()->with('product')->whereKey($segmentIds)->get();
    }

    /**
     * What a notification calls this bulletin's matched events: each distinct event name once, in
     * bulletin order. With no segments it is the bulletin's own catalog product, as before.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments  any collection of segments (an Eloquent one or a plain one)
     * @return list<string>
     */
    public function eventNamesFor(Collection $segments): array
    {
        if ($segments->isEmpty()) {
            $name = $this->product?->name;

            return filled($name) ? [$name] : [];
        }

        return $segments->map(fn (IngestedProductSegment $segment): string => $segment->eventName())->unique()->values()->all();
    }
```

In `app/Models/DeliveryLog.php` add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and this method after `ingestedProduct()`:

```php
    /**
     * The events of the bulletin this delivery covered (empty for deliveries logged before
     * segments existed and for non-VTEC products).
     */
    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(IngestedProductSegment::class, 'delivery_log_segment');
    }
```

In `app/Models/ActiveWwa.php`: change `vtecKeyFor()`'s signature to `public static function vtecKeyFor(IngestedProduct|IngestedProductSegment|self $product): array` (its body is unchanged; update the docblock sentence to say "an IngestedProduct, an IngestedProductSegment or an ActiveWwa row (all carry the five vtec_* columns)") and add this scope after `scopeForProduct()`:

```php
    /**
     * Scopes to the event a segment names (its VTEC key), within the is_test flag of the segment's
     * bulletin, exactly as scopeForProduct() does for a bulletin: a synthetic event never resolves
     * for a real segment or the reverse.
     */
    public function scopeForSegment(Builder $query, IngestedProductSegment $segment): Builder
    {
        return $query->where(static::vtecKeyFor($segment))->where('is_test', (bool) $segment->ingestedProduct->is_test);
    }
```

- [ ] **Step 6: Include the new tables in the factory-reset actions**

Replace `app/Actions/Admin/FactoryReset/WipePipelineData.php`'s method and docblock with:

```php
    /**
     * @return array{ingested_products: int, ingested_product_segments: int, active_wwas: int, delivery_logs: int}
     */
    public function __invoke(): array
    {
        $counts = [
            'ingested_products' => DB::table('ingested_products')->count(),
            'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
            'active_wwas' => DB::table('active_wwas')->count(),
            'delivery_logs' => DB::table('delivery_logs')->count(),
        ];

        DB::statement('TRUNCATE TABLE ingested_products, ingested_product_segments, delivery_log_segment, active_wwas, delivery_logs RESTART IDENTITY');

        return $counts;
    }
```

In `app/Actions/Admin/FactoryReset/PreviewFactoryReset.php` replace the `$preview['pipeline']` array with:

```php
            $preview['pipeline'] = [
                'ingested_products' => DB::table('ingested_products')->count(),
                'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
                'active_wwas' => DB::table('active_wwas')->count(),
                'delivery_logs' => DB::table('delivery_logs')->count(),
            ];
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/IngestedProductSegmentTest.php tests/Feature/FactoryResetActionsTest.php tests/Feature/ActiveWwaModelTest.php tests/Feature/ResetToFactoryDefaultsPageTest.php'`
Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Models/IngestedProductSegment.php app/Models/IngestedProduct.php app/Models/DeliveryLog.php app/Models/ActiveWwa.php app/Actions/Admin/FactoryReset database/factories/IngestedProductSegmentFactory.php database/migrations/2026_10_07_100001_create_ingested_product_segments_table.php database/migrations/2026_10_07_100002_create_delivery_log_segment_table.php tests/Feature/IngestedProductSegmentTest.php tests/Feature/FactoryResetActionsTest.php'
git add app/Models app/Actions/Admin database/factories/IngestedProductSegmentFactory.php database/migrations/2026_10_07_100001_create_ingested_product_segments_table.php database/migrations/2026_10_07_100002_create_delivery_log_segment_table.php tests/Feature/IngestedProductSegmentTest.php tests/Feature/FactoryResetActionsTest.php
git commit -m "Pipeline: add bulletin segment tables and models

One row per (section x VTEC line) of a VTEC bulletin, and a pivot linking a delivery to the
events it covered. The factory-reset wipe and preview include both tables.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Parse a bulletin into segments and store them

**Files:**
- Create: `app/Actions/Pipeline/ParseBulletinSegments.php`, `app/Actions/Pipeline/BuildBulletinSegments.php`
- Test: `tests/Feature/ParseBulletinSegmentsTest.php` (create), `tests/Feature/BuildBulletinSegmentsTest.php` (create)

**Interfaces:**
- Consumes (Tasks 1-2): `ParseUgcLine`, `ParseLatLonPolygon`, `ParseVtecString::all()`, `IngestedProduct::segments()`, `IngestedProductSegment` (fillable columns).
- Produces: `(new ParseBulletinSegments(...))(string $text): list<array{vtec_office: string, vtec_phenomenon: string, vtec_significance: string, vtec_event_number: string, vtec_year: int, vtec_action: string, vtec_begin: ?Carbon, vtec_end: ?Carbon, product_id: ?int, ugc_codes: ?list<string>, polygon: ?array}>` (one entry per (section x VTEC line), bulletin order; empty when no section carries a VTEC line); `app(BuildBulletinSegments::class)(IngestedProduct $bulletin): void` (idempotent: replaces the bulletin's segments; does nothing without `vtec_office`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ParseBulletinSegmentsTest.php`:

```php
<?php

use App\Actions\Pipeline\ParseBulletinSegments;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function bulletinSegmentsFixture(string $name): string
{
    return file_get_contents(base_path("database/product_examples/bulletins/{$name}.txt"));
}

test('a Hurricane Local Statement gives one segment per zone section and VTEC line, in bulletin order', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('TCV_MOB'));

    // Seven sections carry a Hurricane Watch and a Storm Surge Watch, five only the Hurricane Watch.
    expect($segments)->toHaveCount(19)
        ->and(collect($segments)->pluck('vtec_event_number')->unique()->all())->toBe(['1009'])
        ->and(collect($segments)->take(4)->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'].' '.implode(',', $s['ugc_codes']))->all())
        ->toBe(['HU.A ALZ266', 'SS.A ALZ266', 'HU.A ALZ265', 'SS.A ALZ265']);
});

test('the zone Destin sits in has a Hurricane Watch segment of its own', function () {
    $destin = collect(app(ParseBulletinSegments::class)(bulletinSegmentsFixture('TCV_MOB')))
        ->filter(fn (array $s): bool => $s['ugc_codes'] === ['FLZ206']);

    expect($destin->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'])->values()->all())->toBe(['HU.A', 'SS.A']);
});

test('a segment links the catalog product, and none for an event the catalog lacks', function () {
    $coastalFlood = Product::where('code', 'CF.Y')->sole();
    // The catalog is a curated subset: it has no Rip Current Statement (RP.S).
    expect(Product::where('code', 'RP.S')->exists())->toBeFalse();

    $segments = collect(app(ParseBulletinSegments::class)(bulletinSegmentsFixture('CFW_JAX')));

    expect($segments->first(fn (array $s): bool => $s['vtec_phenomenon'] === 'CF')['product_id'])->toBe($coastalFlood->id)
        ->and($segments->first(fn (array $s): bool => $s['vtec_phenomenon'] === 'RP')['product_id'])->toBeNull();
});

test('a section with two VTEC lines gives two segments sharing its zones, and the next section its own zones', function () {
    Carbon::setTestNow('2026-10-07T16:00:00Z');

    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('MWW_MOB'));

    expect($segments)->toHaveCount(3)
        ->and($segments[0]['ugc_codes'])->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and($segments[1]['ugc_codes'])->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and([$segments[0]['vtec_phenomenon'], $segments[0]['vtec_action'], $segments[0]['vtec_event_number']])->toBe(['HU', 'NEW', '1009'])
        ->and([$segments[1]['vtec_phenomenon'], $segments[1]['vtec_action'], $segments[1]['vtec_event_number']])->toBe(['SC', 'CON', '0031'])
        ->and($segments[1]['vtec_begin'])->toBeNull()
        ->and($segments[1]['vtec_end'])->toEqual(Carbon::parse('2026-10-09T08:00:00Z'))
        ->and($segments[0]['vtec_begin'])->toEqual(Carbon::parse('2026-10-07T15:38:00Z'))
        ->and($segments[0]['vtec_end'])->toBeNull()
        ->and($segments[2]['ugc_codes'])->toBe(['GMZ630', 'GMZ631', 'GMZ632', 'GMZ633', 'GMZ634', 'GMZ635', 'GMZ636']);
});

test('a bulletin whose zone line is a full-code range gets its zones', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('NPW_FGZ'));

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['vtec_phenomenon'].'.'.$segments[0]['vtec_significance'])->toBe('WI.Y')
        ->and($segments[0]['vtec_action'])->toBe('COR')
        ->and($segments[0]['ugc_codes'])->toHaveCount(19);
});

test('a Coastal Hazard Message keeps each section\'s own events and zones', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('CFW_JAX'));

    expect(collect($segments)->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'].'#'.$s['vtec_event_number'])->all())
        ->toBe(['CF.Y#0003', 'RP.S#0032', 'CF.Y#0004', 'CF.Y#0003', 'RP.S#0032'])
        ->and($segments[0]['ugc_codes'])->toBe(['FLZ125'])
        ->and($segments[2]['ugc_codes'])->toBe(['FLZ132', 'FLZ137', 'FLZ325', 'FLZ633']);
});

test('each section keeps its own polygon, and a section without one has none', function () {
    $text = <<<'TEXT'

    WGUS51 KOKX 061800
    FFWOKX

    NYZ069-070-081200-
    /O.NEW.KOKX.FF.W.0042.260906T1800Z-260906T2100Z/
    Flash Flood Warning for Westchester.

    LAT...LON 4031 10526 4040 10501 4012 10490 3995 10510

    $$

    NYZ071-081200-
    /O.NEW.KOKX.FF.W.0043.260906T1800Z-260906T2100Z/
    Flash Flood Warning for Putnam.

    $$
    TEXT;

    $segments = app(ParseBulletinSegments::class)($text);

    expect($segments)->toHaveCount(2)
        ->and($segments[0]['polygon'])->toHaveCount(4)
        ->and($segments[0]['polygon'][0])->toEqual(['lat' => 40.31, 'lon' => -105.26])
        ->and($segments[0]['ugc_codes'])->toBe(['NYZ069', 'NYZ070'])
        ->and($segments[1]['polygon'])->toBeNull()
        ->and($segments[1]['ugc_codes'])->toBe(['NYZ071']);
});

test('a section with a VTEC line but no readable zone line still gives a segment, with no zones', function () {
    $segments = app(ParseBulletinSegments::class)("Some header\n/O.NEW.KOKX.FF.W.0042.260906T1800Z-260906T2100Z/\nNo zones here.\n\n\$\$\n");

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['ugc_codes'])->toBeNull();
});

test('text without a VTEC line, or a section with zones but no VTEC line, gives no segments', function () {
    expect(app(ParseBulletinSegments::class)('Area Forecast Discussion. No VTEC here.'))->toBe([])
        ->and(app(ParseBulletinSegments::class)("NYZ069-081200-\nNo VTEC line in this section.\n\n\$\$\n"))->toBe([]);
});
```

Create `tests/Feature/BuildBulletinSegmentsTest.php`:

```php
<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Pipeline\ParseBulletinSegments;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;

/** A bulletin made without the observer, so no job builds segments behind the test's back. */
function buildSegmentsBulletin(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

function buildSegmentsFixtureBulletin(string $example, array $attributes = []): IngestedProduct
{
    return buildSegmentsBulletin([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text',
        'vtec_office' => 'KMOB',
        ...$attributes,
    ]);
}

test('stores one row per section and VTEC line, numbered in bulletin order', function () {
    $bulletin = buildSegmentsFixtureBulletin('TCV_MOB');

    app(BuildBulletinSegments::class)($bulletin);

    $segments = $bulletin->segments()->get();
    expect($segments)->toHaveCount(19)
        ->and($segments->pluck('position')->all())->toBe(range(0, 18))
        ->and($segments[0]->ugc_codes)->toBe(['ALZ266'])
        ->and($segments[0]->product_id)->toBe(Product::where('code', 'HU.A')->sole()->id);
});

test('is idempotent: building again replaces the segments rather than adding to them', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');

    app(BuildBulletinSegments::class)($bulletin);
    app(BuildBulletinSegments::class)($bulletin);

    expect(IngestedProductSegment::count())->toBe(3);
});

test('rebuilding after the text changed leaves no stale segment', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');
    app(BuildBulletinSegments::class)($bulletin);

    $bulletin->update(['text' => file_get_contents(base_path('database/product_examples/bulletins/NPW_FGZ.txt'))]);
    app(BuildBulletinSegments::class)($bulletin->fresh());

    expect(IngestedProductSegment::count())->toBe(1)
        ->and(IngestedProductSegment::sole()->vtec_phenomenon)->toBe('WI');
});

test('a CAP message gets exactly one segment, taken from its own columns', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = buildSegmentsBulletin([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'product_id' => $hurricaneWatch->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009',
        'vtec_year' => 2026, 'vtec_action' => 'CON', 'ugc_codes' => ['FLZ206'],
        'polygon' => [['lat' => 30.0, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.5]],
    ]);

    app(BuildBulletinSegments::class)($bulletin);

    $segment = IngestedProductSegment::sole();
    expect($segment->ingested_product_id)->toBe($bulletin->id)
        ->and($segment->position)->toBe(0)
        ->and($segment->product_id)->toBe($hurricaneWatch->id)
        ->and($segment->vtec_action)->toBe('CON')
        ->and($segment->ugc_codes)->toBe(['FLZ206'])
        ->and($segment->polygon)->toHaveCount(3);
});

test('a text bulletin whose sections cannot be read falls back to one segment from its own columns', function () {
    $bulletin = buildSegmentsBulletin([
        'format' => 'text', 'text' => 'Garbled bulletin with no readable sections.', 'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '0042',
        'vtec_year' => 2026, 'vtec_action' => 'NEW', 'ugc_codes' => ['NYZ069'],
    ]);

    app(BuildBulletinSegments::class)($bulletin);

    $segment = IngestedProductSegment::sole();
    expect($segment->ugc_codes)->toBe(['NYZ069'])
        ->and($segment->vtec_phenomenon)->toBe('TO')
        ->and($segment->product_id)->toBe($bulletin->product_id);
});

test('a bulletin with no VTEC office gets no segments', function () {
    $bulletin = buildSegmentsBulletin(['text' => 'Area Forecast Discussion', 'vtec_office' => null]);

    app(BuildBulletinSegments::class)($bulletin);

    expect(IngestedProductSegment::count())->toBe(0);
});

test('a parse failure propagates and leaves the existing segments in place', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');
    app(BuildBulletinSegments::class)($bulletin);
    $failing = Mockery::mock(ParseBulletinSegments::class);
    $failing->shouldReceive('__invoke')->andThrow(new RuntimeException('boom'));

    expect(fn () => (new BuildBulletinSegments($failing))($bulletin))->toThrow(RuntimeException::class, 'boom')
        ->and(IngestedProductSegment::count())->toBe(3);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ParseBulletinSegmentsTest.php tests/Feature/BuildBulletinSegmentsTest.php'`
Expected: FAIL (classes `ParseBulletinSegments` and `BuildBulletinSegments` not found).

- [ ] **Step 3: Create `ParseBulletinSegments`**

Create `app/Actions/Pipeline/ParseBulletinSegments.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Splits a VTEC text bulletin into its events. A bulletin is a list of sections separated by
 * "$$"; each section has its own zone line, one or more VTEC lines (one per event it covers) and
 * its own LAT...LON polygon. One entry comes back per (section x VTEC line), in bulletin order,
 * so a section with two VTEC lines gives two entries that share the section's zones and polygon.
 * A section without a VTEC line gives none. A section whose zone line cannot be read still gives
 * its entries, with null zones.
 *
 * The catalog product of an entry is resolved from its own VTEC phenomenon and significance
 * ("HU.A"), exactly as ExtractProductMetadata does for a bulletin's first VTEC line; null when the
 * catalog has no such event (Storm Surge, Rip Current and others).
 */
class ParseBulletinSegments
{
    /** @var array<string, ?int> */
    private array $productIds = [];

    public function __construct(
        private readonly ParseVtecString $parseVtec,
        private readonly ParseUgcLine $parseUgc,
        private readonly ParseLatLonPolygon $parsePolygon,
    ) {}

    /**
     * @return list<array{vtec_office: string, vtec_phenomenon: string, vtec_significance: string, vtec_event_number: string, vtec_year: int, vtec_action: string, vtec_begin: ?Carbon, vtec_end: ?Carbon, product_id: ?int, ugc_codes: ?list<string>, polygon: ?array<int, array{lat: float, lon: float}>}>
     */
    public function __invoke(string $text): array
    {
        $segments = [];

        foreach (preg_split('/^\$\$\s*$/m', $text) as $section) {
            $events = $this->parseVtec->all($section);

            if ($events === []) {
                continue;
            }

            $ugcCodes = ($this->parseUgc)($section);
            $polygon = ($this->parsePolygon)($section);

            foreach ($events as $event) {
                $segments[] = [
                    'vtec_office' => $event['office'],
                    'vtec_phenomenon' => $event['phenomenon'],
                    'vtec_significance' => $event['significance'],
                    'vtec_event_number' => $event['event_number'],
                    'vtec_year' => $event['year'],
                    'vtec_action' => $event['action'],
                    'vtec_begin' => $event['begin'],
                    'vtec_end' => $event['end'],
                    'product_id' => $this->productIdFor($event['phenomenon'], $event['significance']),
                    'ugc_codes' => $ugcCodes,
                    'polygon' => $polygon,
                ];
            }
        }

        return $segments;
    }

    private function productIdFor(string $phenomenon, string $significance): ?int
    {
        $code = "{$phenomenon}.{$significance}";

        if (! array_key_exists($code, $this->productIds)) {
            $this->productIds[$code] = Product::where('code', $code)->value('id');
        }

        return $this->productIds[$code];
    }
}
```

- [ ] **Step 4: Create `BuildBulletinSegments`**

Create `app/Actions/Pipeline/BuildBulletinSegments.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\DB;

/**
 * Fills ingested_product_segments for a VTEC bulletin: what MatchWeatherAlertRules matches
 * instead of the bulletin's first zone block and first VTEC line. Idempotent: it parses first,
 * then replaces the bulletin's segments in one transaction, so a parse failure leaves the old
 * segments untouched.
 *
 * A text bulletin is split by ParseBulletinSegments. A CAP message always gets exactly one
 * segment, copied from its already-extracted columns (it is one zone's message of an event). A
 * text bulletin whose sections cannot be found falls back to the same single segment, so an odd
 * bulletin never loses its alert. A product with no vtec_office (AFD, forecasts) gets none.
 */
class BuildBulletinSegments
{
    public function __construct(private readonly ParseBulletinSegments $parseSegments) {}

    public function __invoke(IngestedProduct $bulletin): void
    {
        if ($bulletin->vtec_office === null) {
            return;
        }

        $rows = $bulletin->format === 'cap' ? [] : ($this->parseSegments)((string) $bulletin->text);

        if ($rows === []) {
            $rows = [$this->fromBulletin($bulletin)];
        }

        DB::transaction(function () use ($bulletin, $rows): void {
            $bulletin->segments()->delete();

            foreach (array_values($rows) as $position => $row) {
                $bulletin->segments()->create([...$row, 'position' => $position]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromBulletin(IngestedProduct $bulletin): array
    {
        return [
            'vtec_office' => $bulletin->vtec_office,
            'vtec_phenomenon' => $bulletin->vtec_phenomenon,
            'vtec_significance' => $bulletin->vtec_significance,
            'vtec_event_number' => $bulletin->vtec_event_number,
            'vtec_year' => $bulletin->vtec_year,
            'vtec_action' => $bulletin->vtec_action,
            'vtec_begin' => $bulletin->vtec_begin,
            'vtec_end' => $bulletin->vtec_end,
            'product_id' => $bulletin->product_id,
            'ugc_codes' => $bulletin->ugc_codes,
            'polygon' => $bulletin->polygon,
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ParseBulletinSegmentsTest.php tests/Feature/BuildBulletinSegmentsTest.php'`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Pipeline/ParseBulletinSegments.php app/Actions/Pipeline/BuildBulletinSegments.php tests/Feature/ParseBulletinSegmentsTest.php tests/Feature/BuildBulletinSegmentsTest.php'
git add app/Actions/Pipeline/ParseBulletinSegments.php app/Actions/Pipeline/BuildBulletinSegments.php tests/Feature/ParseBulletinSegmentsTest.php tests/Feature/BuildBulletinSegmentsTest.php
git commit -m "Pipeline: split a VTEC bulletin into its sections and events

ParseBulletinSegments reads every section and VTEC line; BuildBulletinSegments stores them
idempotently (a CAP message gets one segment, an unreadable bulletin falls back to one).

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

### Task 4: Build the segments first in `ProcessIngestedProduct`, and the `bulletins:parse-segments` command

**Files:**
- Modify: `app/Jobs/ProcessIngestedProduct.php`
- Create: `app/Console/Commands/ParseStoredBulletinSegments.php`
- Test: `tests/Feature/ProcessIngestedProductTest.php`, `tests/Feature/ParseStoredBulletinSegmentsTest.php` (create)

**Interfaces:**
- Consumes (Task 3): `BuildBulletinSegments::__invoke(IngestedProduct): void`.
- Produces: after `ProcessIngestedProduct::handle()` has run its first step, a VTEC bulletin's segments exist before any matching or syncing reads them; artisan command `bulletins:parse-segments {--days=2}`.

The job builds the segments, not `IngestProductText`: the observer queues the job from the model's `created` event, before the ingest action returns, so building at the start of the job guarantees the segments exist when matching reads them, and keeps a parse failure out of the ingest request.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/ProcessIngestedProductTest.php` add `use App\Actions\Pipeline\BuildBulletinSegments;` to the imports and append:

```php
test('the segments are built before a replay is matched', function () {
    $segmentCount = null;
    $matchMock = Mockery::mock(MatchWeatherAlertRules::class);
    $matchMock->shouldReceive('__invoke')->once()->andReturnUsing(function (IngestedProduct $bulletin) use (&$segmentCount): void {
        $segmentCount = $bulletin->segments()->count();
    });
    app()->instance(MatchWeatherAlertRules::class, $matchMock);
    [$original, $replay] = IngestedProduct::withoutEvents(function () {
        $original = IngestedProduct::factory()->create(['vtec_office' => 'KMOB']);

        return [$original, IngestedProduct::factory()->create([
            'replay_of_id' => $original->id, 'vtec_office' => 'KMOB', 'format' => 'text',
            'text' => file_get_contents(base_path('database/product_examples/bulletins/MWW_MOB.txt')),
        ])];
    });

    (new ProcessIngestedProduct($replay->id))->handle();

    expect($segmentCount)->toBe(3);
});

test('the segments are built before the active event is synced', function () {
    $segmentCount = null;
    $syncMock = Mockery::mock(SyncActiveWwa::class);
    $syncMock->shouldReceive('__invoke')->once()->andReturnUsing(function (IngestedProduct $bulletin) use (&$segmentCount): void {
        $segmentCount = $bulletin->segments()->count();
    });
    app()->instance(SyncActiveWwa::class, $syncMock);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'vtec_office' => 'KMOB', 'format' => 'text',
        'text' => file_get_contents(base_path('database/product_examples/bulletins/MWW_MOB.txt')),
    ]));

    (new ProcessIngestedProduct($bulletin->id))->handle();

    expect($segmentCount)->toBe(3);
});

test('a failure building the segments is logged and the bulletin is still processed', function () {
    Log::spy();
    $failing = Mockery::mock(BuildBulletinSegments::class);
    $failing->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('boom'));
    app()->instance(BuildBulletinSegments::class, $failing);
    $syncSpy = Mockery::spy(SyncActiveWwa::class);
    app()->instance(SyncActiveWwa::class, $syncSpy);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['vtec_office' => 'KMOB']));

    (new ProcessIngestedProduct($bulletin->id))->handle();

    $syncSpy->shouldHaveReceived('__invoke')->once();
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($message, $context) => $context['ingested_product_id'] === $bulletin->id && $context['exception'] === 'boom');
});

test('a product without a VTEC office builds no segments', function () {
    $build = Mockery::mock(BuildBulletinSegments::class);
    $build->shouldNotReceive('__invoke');
    app()->instance(BuildBulletinSegments::class, $build);
    $matchSpy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matchSpy);
    $afd = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['vtec_office' => null]));

    (new ProcessIngestedProduct($afd->id))->handle();

    $matchSpy->shouldHaveReceived('__invoke')->once();
});
```

Create `tests/Feature/ParseStoredBulletinSegmentsTest.php`:

```php
<?php

use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Queue;

function storedBulletinForSegments(string $example, array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text',
        'vtec_office' => 'KMOB',
        ...$attributes,
    ]));
}

test('rebuilds the segments of the stored VTEC bulletins in the window, and sends nothing', function () {
    Queue::fake();
    $recent = storedBulletinForSegments('MWW_MOB', ['received_at' => now()->subHours(3)]);
    $old = storedBulletinForSegments('NPW_FGZ', ['received_at' => now()->subDays(5)]);
    $afd = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['vtec_office' => null, 'received_at' => now()]));

    $this->artisan('bulletins:parse-segments')
        ->expectsOutputToContain('Rebuilt the segments of 1 bulletin')
        ->assertSuccessful();

    expect($recent->segments()->count())->toBe(3)
        ->and($old->segments()->count())->toBe(0)
        ->and($afd->segments()->count())->toBe(0)
        ->and(DeliveryLog::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('--days widens the window, and running it again leaves the same segments', function () {
    $old = storedBulletinForSegments('NPW_FGZ', ['received_at' => now()->subDays(5)]);

    $this->artisan('bulletins:parse-segments', ['--days' => 7])->assertSuccessful();
    $this->artisan('bulletins:parse-segments', ['--days' => 7])->assertSuccessful();

    expect($old->segments()->count())->toBe(1);
});

test('--days must be at least 1', function () {
    $this->artisan('bulletins:parse-segments', ['--days' => 0])
        ->expectsOutputToContain('--days must be at least 1')
        ->assertFailed();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ProcessIngestedProductTest.php tests/Feature/ParseStoredBulletinSegmentsTest.php'`
Expected: FAIL (no segments are built by the job; the command does not exist).

- [ ] **Step 3: Build the segments first in the job**

In `app/Jobs/ProcessIngestedProduct.php`:

1. Add the imports `use App\Actions\Pipeline\BuildBulletinSegments;` (before `BackfillOutlookRiskAreas`, keeping alphabetical order) and `use Throwable;`.
2. Append this paragraph to the class docblock, before its closing `*/`:

```
 *
 * Before any of that branching, a VTEC bulletin's segments (one per section x VTEC line) are
 * built, for replays too: matching reads them instead of the bulletin's first zone block and first
 * VTEC line, so they must exist first. It is built here and not in IngestProductText because the
 * observer queues this job from the model's created event, before the ingest action returns.
```

3. In `handle()`, insert `$this->buildSegments($product);` immediately before `if ($product->replay_of_id !== null) {`.
4. Add this method after `handle()`:

```php
    /**
     * A failure is logged, never thrown: a bulletin whose sections cannot be split is still
     * processed, the old way, with no segments (BuildBulletinSegments parses before it deletes, so
     * a failure leaves the previous segments, if any, in place).
     */
    private function buildSegments(IngestedProduct $product): void
    {
        if ($product->vtec_office === null) {
            return;
        }

        try {
            app(BuildBulletinSegments::class)($product);
        } catch (Throwable $e) {
            logger()->error('ProcessIngestedProduct: failed to build the segments of a bulletin', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 4: Create the command**

Create `app/Console/Commands/ParseStoredBulletinSegments.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Models\IngestedProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rebuilds the segments of the VTEC bulletins stored in the last N days. Run once after the
 * section-aware pipeline is deployed so the currently active events have segments (the active
 * event coverage rebuild reads them). It parses and stores only: it sends nothing, dispatches no
 * job and writes no delivery log, and running it again changes nothing.
 */
class ParseStoredBulletinSegments extends Command
{
    protected $signature = 'bulletins:parse-segments {--days=2 : How many days of stored bulletins to rebuild}';

    protected $description = 'Rebuild the sections (segments) of the stored VTEC bulletins; sends nothing';

    public function handle(BuildBulletinSegments $build): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $rebuilt = 0;
        $failed = 0;

        IngestedProduct::query()
            ->whereNotNull('vtec_office')
            ->where('received_at', '>=', now()->subDays($days))
            ->chunkById(200, function ($bulletins) use ($build, &$rebuilt, &$failed): void {
                foreach ($bulletins as $bulletin) {
                    try {
                        $build($bulletin);
                        $rebuilt++;
                    } catch (Throwable $e) {
                        $failed++;
                        logger()->warning('bulletins:parse-segments: failed to rebuild a bulletin', [
                            'ingested_product_id' => $bulletin->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Rebuilt the segments of {$rebuilt} ".Str::plural('bulletin', $rebuilt)." ({$failed} failed).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ProcessIngestedProductTest.php tests/Feature/ParseStoredBulletinSegmentsTest.php tests/Feature/IngestedProductReplayObserverTest.php tests/Feature/ReplayIngestedProductTest.php tests/Feature/SyncActiveWwaTest.php tests/Feature/CapIngestionIntegrationTest.php'`
Expected: PASS (the replay, sync and CAP tests build fallback segments behind the scenes and still pass).

- [ ] **Step 6: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Jobs/ProcessIngestedProduct.php app/Console/Commands/ParseStoredBulletinSegments.php tests/Feature/ProcessIngestedProductTest.php tests/Feature/ParseStoredBulletinSegmentsTest.php'
git add app/Jobs/ProcessIngestedProduct.php app/Console/Commands/ParseStoredBulletinSegments.php tests/Feature/ProcessIngestedProductTest.php tests/Feature/ParseStoredBulletinSegmentsTest.php
git commit -m "Pipeline: build a bulletin's segments first in the processing job

Also adds bulletins:parse-segments to build them for bulletins already stored. Neither sends
anything.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Name every matched event in the subject, and record which events a delivery covered

**Files:**
- Modify: `app/Actions/Weather/RenderAlertSubject.php`, `app/Actions/Weather/ComposeTemplatedAlertBody.php`, `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/RenderAlertSubjectTest.php`, `tests/Feature/ComposeTemplatedAlertBodyTest.php`, `tests/Feature/DeliverWeatherAlertTest.php`

**Interfaces:**
- Consumes (Task 2): `IngestedProduct::matchedSegments(array): EloquentCollection`, `eventNamesFor(Collection): list<string>`, `DeliveryLog::segments()`, `ActiveWwa::scopeForSegment()`.
- Produces: `RenderAlertSubject::__invoke(AlertRule, Product|array|null $product = null, ?Location = null)` where an array is a list of event names; `ComposeTemplatedAlertBody::__invoke(AlertRule, IngestedProduct, ?IngestedProductSegment $segment = null)`; `new DeliverWeatherAlert(int $ruleId, int $channelId, int $ingestedProductId, ?int $locationId = null, array $segmentIds = [])`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RenderAlertSubjectTest.php`:

```php
test('a list of event names fills {product} joined with a plus', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => '{product} for {location}',
    ]);

    expect((new RenderAlertSubject)($rule, ['Hurricane Watch', 'Small Craft Advisory']))->toBe('Hurricane Watch + Small Craft Advisory for your area');
});

test('without a template, several fired events title the alert, joined with a plus', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'name' => null]);

    expect((new RenderAlertSubject)($rule, ['Hurricane Watch', 'Small Craft Advisory']))->toBe('Hurricane Watch + Small Craft Advisory')
        ->and((new RenderAlertSubject)($rule, ['Hurricane Watch']))->toBe('Hurricane Watch');
});

test('a name the subscriber gave the rule still wins over the fired event names', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'name' => 'Gulf watch']);

    expect((new RenderAlertSubject)($rule, ['Hurricane Watch', 'Small Craft Advisory']))->toBe('Gulf watch');
});

test('an empty list of event names keeps displayName() and the category label for {product}', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $unnamed = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'name' => null]);
    $templated = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => '{product}',
    ]);

    expect((new RenderAlertSubject)($unnamed, []))->toBe($unnamed->displayName())
        ->and((new RenderAlertSubject)($templated, []))->toBe('Weather Alerts');
});
```

Append to `tests/Feature/ComposeTemplatedAlertBodyTest.php` (add `use App\Models\IngestedProductSegment;` to its imports):

```php
test('reads the expiration of the matched segment\'s event, not of the bulletin\'s first VTEC line', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->create(['sections' => [AlertTemplateSection::Expiration->value]]);
    $rule = AlertRule::factory()->for($plusUser)->create(['category' => 'weather_alerts', 'alert_template_id' => $template->id]);
    $firstEvent = [...vtecKeyFor(), 'vtec_event_number' => '1009', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A'];
    $secondEvent = [...vtecKeyFor(), 'vtec_event_number' => '0031', 'vtec_phenomenon' => 'SC', 'vtec_significance' => 'Y'];
    ActiveWwa::factory()->create([...$firstEvent, 'expires' => now()->addHour()]);
    ActiveWwa::factory()->create([...$secondEvent, 'expires' => now()->addHours(6)]);
    $bulletin = IngestedProduct::factory()->createQuietly($firstEvent);
    $segment = IngestedProductSegment::factory()->for($bulletin)->create($secondEvent);

    expect((new ComposeTemplatedAlertBody)($rule, $bulletin, $segment))->toBe('Expiration: 6 hours from now')
        ->and((new ComposeTemplatedAlertBody)($rule, $bulletin))->toBe('Expiration: 1 hour from now');
});
```

Append to `tests/Feature/DeliverWeatherAlertTest.php` (add `use App\Models\IngestedProductSegment;`, `use App\Support\Delivery\DeliveryResult;` and `use Illuminate\Support\Facades\Http;` is already imported):

```php
/** A weather alerts rule with one apprise channel, ready to deliver; $ruleAttributes override the defaults. */
function segmentDeliveryRule(array $ruleAttributes = [], ?User $owner = null): array
{
    $rule = AlertRule::factory()->for($owner ?? User::factory()->create())->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'name' => null, ...$ruleAttributes,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    return [$rule, $channel];
}

/** A bulletin with a Hurricane Watch segment and a Rip Current Statement one (the catalog has no RP.S). */
function segmentDeliveryBulletin(): array
{
    $bulletin = IngestedProduct::factory()->createQuietly(['text' => 'Hurricane Local Statement...']);
    $watch = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0]);
    $statement = IngestedProductSegment::factory()->for($bulletin)->create(['position' => 1, 'vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'vtec_event_number' => '0032', 'product_id' => null]);

    return [$bulletin, $watch, $statement];
}

test('a delivery for several matched events is titled with all of them and records which events it covered', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule();
    [$bulletin, $watch, $statement] = segmentDeliveryBulletin();

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, $statement->id]))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('success')
        ->and($log->subject)->toBe('Hurricane Watch + Rip Current Statement')
        ->and($log->segments()->pluck('ingested_product_segments.id')->sort()->values()->all())->toBe([$watch->id, $statement->id]);
});

test('an event named twice in one bulletin appears once in the subject', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule();
    $bulletin = IngestedProduct::factory()->createQuietly();
    $one = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0, 'ugc_codes' => ['FLZ206']]);
    $two = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 1, 'ugc_codes' => ['FLZ203']]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$one->id, $two->id]))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->subject)->toBe('Hurricane Watch')
        ->and($log->segments()->count())->toBe(2);
});

test('a rule the subscriber named keeps its name as the subject and still records the events', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule(['name' => 'Gulf watch']);
    [$bulletin, $watch, $statement] = segmentDeliveryBulletin();

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, $statement->id]))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->subject)->toBe('Gulf watch')
        ->and($log->segments()->count())->toBe(2);
});

test('{product} in a custom subject becomes the joined list of matched events', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule(['custom_subject_template' => '{product} - {location}'], User::factory()->create(['tier_override' => Tier::Plus]));
    [$bulletin, $watch, $statement] = segmentDeliveryBulletin();

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, $statement->id]))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->subject)->toBe('Hurricane Watch + Rip Current Statement - your area');
});

test('a segment that no longer exists is ignored, and only the ones that do are linked', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule();
    [$bulletin, $watch] = segmentDeliveryBulletin();

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, 999999]))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->subject)->toBe('Hurricane Watch')
        ->and($log->segments()->pluck('ingested_product_segments.id')->all())->toBe([$watch->id]);
});

test('a delivery with no segment ids is titled with the bulletin\'s own product and links nothing, as before', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    [$rule, $channel] = segmentDeliveryRule();
    $bulletin = IngestedProduct::factory()->createQuietly(['product_id' => Product::where('code', 'TO.W')->sole()->id]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->subject)->toBe('Tornado Warning')
        ->and($log->segments()->count())->toBe(0);
});

test('the dispatcher is told the joined event names as the product', function () {
    [$rule, $channel] = segmentDeliveryRule();
    [$bulletin, $watch, $statement] = segmentDeliveryBulletin();
    $dispatcher = Mockery::mock(ChannelDispatcher::class);
    $dispatcher->shouldReceive('send')
        ->once()
        ->withArgs(fn ($sentChannel, $body, $subject, $meta) => $subject === 'Hurricane Watch + Rip Current Statement' && $meta['product'] === 'Hurricane Watch + Rip Current Statement')
        ->andReturn(DeliveryResult::delivered());

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, $statement->id]))->handle($dispatcher);

    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('a delivery skipped because the rule was switched off still names and records its events', function () {
    Http::fake();
    [$rule, $channel] = segmentDeliveryRule(['is_active' => false]);
    [$bulletin, $watch, $statement] = segmentDeliveryBulletin();

    (new DeliverWeatherAlert($rule->id, $channel->id, $bulletin->id, null, [$watch->id, $statement->id]))->handle(app(ChannelDispatcher::class));

    Http::assertNothingSent();
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')
        ->and($log->subject)->toBe('Hurricane Watch + Rip Current Statement')
        ->and($log->segments()->count())->toBe(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/DeliverWeatherAlertTest.php'`
Expected: FAIL (a `RenderAlertSubject` call with an array is a TypeError, `DeliverWeatherAlert` has no `segmentIds`, `ComposeTemplatedAlertBody` takes no segment).

- [ ] **Step 3: Teach `RenderAlertSubject` to name several events**

Replace the body of `app/Actions/Weather/RenderAlertSubject.php` from the class docblock down with:

```php
/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Priority: an attached AlertTemplate's subject_template first
 * (via effectiveAlertTemplate(), tier- and category-gated), then the
 * rule's own custom_subject_template (via
 * effectiveCustomSubjectTemplate()), then AlertRule::displayName() (the
 * existing admin/list-table label, unchanged and untouched by this class)
 * when neither is set.
 *
 * The product argument is either the one Product that fired or, for a
 * bulletin that matched several events (a Hurricane Watch and a Small Craft
 * Advisory in one message), the list of their names in bulletin order. Several
 * names read as "Hurricane Watch + Small Craft Advisory" everywhere a single
 * product name did.
 */
class RenderAlertSubject
{
    /**
     * $location is the specific location that matched THIS delivery for a
     * multi-location Weather Alerts/SPC & WPC rule (see
     * MatchWeatherAlertRules::matchesAnyLocation()) -- when omitted, falls
     * back to $rule->location, exactly this method's behavior before this
     * parameter existed, which is what every non-Weather-Alerts/SPC-WPC
     * caller (DeliverScheduledAlert) still relies on.
     *
     * @param  Product|list<string>|null  $product  the product that fired, or the names of the events that did
     */
    public function __invoke(AlertRule $rule, Product|array|null $product = null, ?Location $location = null): string
    {
        $eventNames = $this->eventNames($product);
        $templateSubject = $rule->effectiveAlertTemplate()?->subject_template;
        $subjectTemplate = filled($templateSubject) ? $templateSubject : $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $this->defaultSubject($rule, $eventNames);
        }

        $location ??= $rule->location;

        return strtr($subjectTemplate, [
            '{product}' => $eventNames === [] ? $rule->category->label() : implode(' + ', $eventNames),
            '{location}' => $location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }

    /**
     * @param  Product|list<string>|null  $product
     * @return list<string>
     */
    private function eventNames(Product|array|null $product): array
    {
        $names = is_array($product) ? $product : [$product?->name];

        return array_values(array_filter($names, 'filled'));
    }

    /**
     * Without a template, a Weather Alerts / SPC & WPC alert is titled with the
     * event(s) that actually fired ("SPC Day 4-8 Convective Outlook"), not every product
     * the rule watches. A name the subscriber gave the rule still wins, and the
     * scheduled categories (no product) keep displayName().
     *
     * @param  list<string>  $eventNames
     */
    private function defaultSubject(AlertRule $rule, array $eventNames): string
    {
        $isProductAlert = in_array($rule->category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true);

        if (! $isProductAlert || filled($rule->name) || $eventNames === []) {
            return $rule->displayName();
        }

        return implode(' + ', $eventNames);
    }
}
```

(Keep the file's existing `<?php`, `namespace` and `use` lines.)

- [ ] **Step 4: Let `ComposeTemplatedAlertBody` read the matched segment's event**

In `app/Actions/Weather/ComposeTemplatedAlertBody.php`: add `use App\Models\IngestedProductSegment;` to the imports; change the signature to `public function __invoke(AlertRule $rule, IngestedProduct $product, ?IngestedProductSegment $segment = null): ?string`; change `$activeWwa = $this->findActiveWwa($product);` to `$activeWwa = $this->findActiveWwa($product, $segment);`; add to the class docblock `Expiration and Motion describe the matched segment's event when one is given (the first one a delivery matched), else the bulletin's first VTEC event; every other section is the bulletin's own narrative.`; and replace `findActiveWwa()` with:

```php
    /**
     * Correlates the event this delivery is about to its current-state ActiveWwa row via the VTEC
     * composite key: the matched segment's event when one is given (ActiveWwa::scopeForSegment()),
     * else the bulletin's first VTEC event (ActiveWwa::scopeForProduct(), the same lookup
     * MatchWeatherAlertRules::matchesActiveWwaPolygon() uses).
     */
    private function findActiveWwa(IngestedProduct $product, ?IngestedProductSegment $segment): ?ActiveWwa
    {
        if ($segment !== null) {
            return ActiveWwa::query()->forSegment($segment)->first();
        }

        if ($product->vtec_office === null) {
            return null;
        }

        return ActiveWwa::query()->forProduct($product)->first();
    }
```

- [ ] **Step 5: Teach `DeliverWeatherAlert` the matched segments**

In `app/Jobs/DeliverWeatherAlert.php`:

1. Add the imports `use Illuminate\Database\Eloquent\Collection;`.
2. Extend the class docblock with: `The matched segment ids (see IngestedProductSegment) name the events this delivery is about: the subject lists each once, the templated body describes the first, and delivery_log_segment records them on the log row, including a skipped one. An id whose segment is gone by the time the job runs is ignored. An empty list (a non-VTEC product, a job queued before segments existed) keeps the bulletin's own product.`
3. Replace the constructor with:

```php
    /**
     * @param  list<int>  $segmentIds  the matched IngestedProductSegment ids, in bulletin order
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
        public readonly ?int $locationId = null,
        public readonly array $segmentIds = [],
    ) {}
```

4. In `handle()`, directly after `$location = $locationId !== null ? Location::find($locationId) : null;` add (the same read-once rule as `$locationId`: a job queued before this property existed has it uninitialized):

```php
        $segments = $product->matchedSegments($this->segmentIds ?? []);
        $eventNames = $product->eventNamesFor($segments);
```

5. Replace the `logSkipped(...)` call in the `! $rule->is_active` branch with `$this->logSkipped($rule, $channel, 'The rule was deactivated after this delivery was dispatched.', $product, app(RenderAlertSubject::class)($rule, $eventNames, $location), $segments);`
6. Replace `$templatedBody = app(ComposeTemplatedAlertBody::class)($rule, $product);` with `$templatedBody = app(ComposeTemplatedAlertBody::class)($rule, $product, $segments->first());`
7. Replace `$subject = app(RenderAlertSubject::class)($rule, $product->product, $location);` with `$subject = app(RenderAlertSubject::class)($rule, $eventNames, $location);`
8. Replace `'product' => $product->product?->name,` in the `send()` meta with `'product' => $eventNames === [] ? null : implode(' + ', $eventNames),`
9. Replace `DeliveryLog::create([...])` after `send()` with `$log = DeliveryLog::create([...same array...]);` followed by `$this->linkSegments($log, $segments);`
10. Change `logSkipped()` to take `?Collection $segments = null` as a last parameter, assign `$log = DeliveryLog::create([...same array...]);` and call `$this->linkSegments($log, $segments ?? new Collection);` after it. Add this method to the class:

```php
    /**
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function linkSegments(DeliveryLog $log, Collection $segments): void
    {
        if ($segments->isNotEmpty()) {
            $log->segments()->attach($segments->modelKeys());
        }
    }
```

(import `App\Models\IngestedProductSegment` for the docblock type.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/DeliveryLogCategoryTest.php tests/Feature/DeliverScheduledAlertTest.php'`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Weather/RenderAlertSubject.php app/Actions/Weather/ComposeTemplatedAlertBody.php app/Jobs/DeliverWeatherAlert.php tests/Feature/RenderAlertSubjectTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/DeliverWeatherAlertTest.php'
git add app/Actions/Weather/RenderAlertSubject.php app/Actions/Weather/ComposeTemplatedAlertBody.php app/Jobs/DeliverWeatherAlert.php tests/Feature/RenderAlertSubjectTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "Delivery: name every matched event in the subject and record which events it covered

One notification per bulletin lists each event once ('Hurricane Watch + Small Craft Advisory');
a named rule keeps its name. The log row links the events through delivery_log_segment.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Match rules against segments

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/BulletinSegmentMatchingTest.php` (create)

**Interfaces:**
- Consumes (Tasks 2-5): `IngestedProduct::segments()`, `asSegment()`, `matchedSegments()`, `eventNamesFor()`, `DeliveryLog::segments()`, `RenderAlertSubject` with a name list, `new DeliverWeatherAlert(..., array $segmentIds)`.
- Produces: for a bulletin with segments, one `DeliverWeatherAlert` per rule and channel carrying all the rule's matching segment ids in bulletin order and the lowest-id matching location; for a bulletin with none, the previous behavior with `segmentIds === []`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BulletinSegmentMatchingTest.php`:

```php
<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Pipeline\IngestProductText;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

/** A Weather Alerts rule watching the given catalog codes at the given locations, with one channel. */
function bsmRule(array|Location $locations, array $codes, string $mode = 'location', array $attributes = []): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null, ...$attributes]);
    $rule->locations()->attach(is_array($locations) ? $locations : [$locations]);
    $rule->products()->attach(Product::whereIn('code', $codes)->pluck('id')->all());
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

function bsmLocation(string $zone, array $attributes = []): Location
{
    return Location::factory()->create(['forecast_zone_ugc' => $zone, ...$attributes]);
}

/** A stored fixture bulletin with its segments built, made without the observer. */
function bsmBulletin(string $example, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text', 'cccc' => 'kmob', 'vtec_office' => 'KMOB', 'received_at' => now(), ...$attributes,
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    return $bulletin;
}

/** A bulletin of hand-made segments: each entry overrides the segment factory's defaults. */
function bsmManualBulletin(array $segments, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['cccc' => 'kmob', 'vtec_office' => 'KMOB', ...$attributes]));

    foreach ($segments as $position => $segment) {
        IngestedProductSegment::factory()->for($bulletin)->create([...$segment, 'position' => $position]);
    }

    return $bulletin;
}

/** The ids of a bulletin's segments in the given phenomenon, in bulletin order. */
function bsmSegmentIds(IngestedProduct $bulletin, string $phenomenon): array
{
    return $bulletin->segments()->where('vtec_phenomenon', $phenomenon)->pluck('id')->all();
}

function bsmSquare(float $lat, float $lng, float $half = 0.1): array
{
    return [
        ['lat' => $lat - $half, 'lon' => $lng - $half], ['lat' => $lat + $half, 'lon' => $lng - $half],
        ['lat' => $lat + $half, 'lon' => $lng + $half], ['lat' => $lat - $half, 'lon' => $lng + $half],
    ];
}

test('Destin is matched by the Hurricane Watch section for its own zone, through the whole pipeline', function () {
    // Only the delivery is faked: the observer's ProcessIngestedProduct job must really run (sync queue).
    Queue::fake([DeliverWeatherAlert::class]);
    $destin = bsmLocation('FLZ206');
    $elsewhere = bsmLocation('FLZ299');
    $destinRule = bsmRule($destin, ['HU.A']);
    $elsewhereRule = bsmRule($elsewhere, ['HU.A']);

    $bulletin = app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/TCV_MOB.txt')),
        'test/tcvmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'wtus84', 'awipsid' => 'tcvmob', 'wmo_ddhhmm' => '071609', 'nwws_id' => '1'],
    );

    $destinWatch = $bulletin->segments()->where('vtec_phenomenon', 'HU')->get()->first(fn (IngestedProductSegment $s): bool => $s->ugc_codes === ['FLZ206']);
    expect($bulletin->ugc_codes)->toBe(['ALZ266']);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $destinRule->id
        && $job->locationId === $destin->id
        && $job->ingestedProductId === $bulletin->id
        && $job->segmentIds === [$destinWatch->id]);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $elsewhereRule->id);
});

test('a rule that watches only the second event of a section matches it though the bulletin\'s first VTEC line is another event', function () {
    Queue::fake();
    $location = bsmLocation('GMZ650');
    $rule = bsmRule($location, ['SC.Y']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $advisory = $bulletin->segments()->where('vtec_phenomenon', 'SC')->sole();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$advisory->id]);
});

test('a rule that watches both events of a section gets one delivery covering both', function () {
    Queue::fake();
    $location = bsmLocation('GMZ655');
    $rule = bsmRule($location, ['HU.A', 'SC.Y']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $firstSection = $bulletin->segments()->get()->take(2)->modelKeys();
    Queue::assertPushedTimes(DeliverWeatherAlert::class, 1);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === $firstSection);
});

test('a location in none of the bulletin\'s sections never matches', function () {
    Queue::fake();
    bsmRule(bsmLocation('GMZ999'), ['HU.A', 'SC.Y']);

    (new MatchWeatherAlertRules)(bsmBulletin('MWW_MOB'));

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a nationwide rule matches every segment of the products it watches', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $watches = $bulletin->segments()->where('vtec_phenomenon', 'HU')->pluck('id')->all();
    expect($watches)->toHaveCount(2);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === $watches && $job->locationId === null);
});

test('a WFO rule matches on the issuing office and carries the segments of the products it watches', function () {
    Queue::fake();
    $rule = bsmRule([], ['SC.Y'], 'wfo', ['wfo' => 'MOB']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === bsmSegmentIds($bulletin, 'SC'));
});

test('a County/Zone rule matches the section whose zones it names', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'county_zone', ['county_zone_codes' => 'GMZ632']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $secondSection = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$secondSection->id]);
});

test('a Polygon rule matches the section whose own polygon holds the location', function () {
    Queue::fake();
    $location = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040']);
    $rule = bsmRule($location, ['FF.W'], 'polygon');
    $bulletin = bsmManualBulletin([
        ['product_id' => Product::where('code', 'FF.W')->sole()->id, 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'ugc_codes' => ['NYZ069'], 'polygon' => bsmSquare(35.0, -90.0)],
        ['product_id' => Product::where('code', 'FF.W')->sole()->id, 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0043', 'ugc_codes' => ['NYZ070'], 'polygon' => bsmSquare(40.0, -105.0)],
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    $inside = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$inside->id]);
});

test('a bulletin whose first VTEC line is an event the catalog lacks is no longer skipped', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmManualBulletin([
        ['product_id' => null, 'vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'vtec_event_number' => '0032'],
        ['product_id' => Product::where('code', 'HU.A')->sole()->id, 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A'],
    ], ['product_id' => null]);

    (new MatchWeatherAlertRules)($bulletin);

    $watch = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$watch->id]);
});

test('the matched location is the lowest-id location that matched any segment', function () {
    Queue::fake();
    $lower = bsmLocation('FLZ206');
    $higher = bsmLocation('FLZ299');
    $rule = bsmRule([$higher, $lower], ['HU.A']);
    $hurricaneWatch = Product::where('code', 'HU.A')->sole()->id;
    $bulletin = bsmManualBulletin([
        ['product_id' => $hurricaneWatch, 'ugc_codes' => ['FLZ299']],
        ['product_id' => $hurricaneWatch, 'ugc_codes' => ['FLZ206']],
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id
        && $job->locationId === $lower->id
        && $job->segmentIds === $bulletin->segments()->pluck('id')->all());
});

test('a CAP message with no text pair matches through its single segment', function () {
    Queue::fake();
    $location = bsmLocation('FLZ206');
    $rule = bsmRule($location, ['HU.A']);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc', 'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'vtec_action' => 'NEW', 'ugc_codes' => ['FLZ206'],
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$bulletin->segments()->sole()->id]);
});

test('a bulletin with no segments is matched on its own columns, exactly as before', function () {
    Queue::fake();
    $location = bsmLocation('FLZ206');
    $rule = bsmRule($location, ['HU.A']);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id, 'ugc_codes' => ['FLZ206'],
    ]));

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === []);
});

test('a rule is never delivered twice for one bulletin', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmBulletin('MWW_MOB');
    $channel = $rule->notificationChannels->first();
    DeliveryLog::factory()->create([
        'alert_rule_id' => $rule->id, 'notification_channel_id' => $channel->id, 'ingested_product_id' => $bulletin->id,
        'user_id' => $rule->user_id, 'channel_type' => $channel->channel_type,
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::count())->toBe(1);
});

test('a quiet-hours delivery is logged as suppressed with every matched event named and linked', function () {
    Queue::fake();
    $location = bsmLocation('GMZ655', ['timezone' => 'America/New_York']);
    $rule = bsmRule($location, ['HU.A', 'SC.Y'], 'location', [
        'name' => null,
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->subject)->toBe('Hurricane Watch + Small Craft Advisory')
        ->and($log->segments()->count())->toBe(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/BulletinSegmentMatchingTest.php'`
Expected: FAIL (the matcher still reads the bulletin's first zone block and first VTEC line, and `DeliverWeatherAlert` is dispatched with no segment ids).

- [ ] **Step 3: Match segment by segment**

In `app/Actions/Weather/MatchWeatherAlertRules.php`:

1. Add the imports `use App\Models\IngestedProductSegment;` and `use Illuminate\Database\Eloquent\Collection;`.
2. Append this paragraph to the class docblock:

```
 *
 * A VTEC bulletin is a list of sections, each with its own zones, polygon and one or more VTEC
 * events, stored as IngestedProductSegment rows (see BuildBulletinSegments). A bulletin that has
 * segments is matched segment by segment, in bulletin order, through IngestedProduct::asSegment():
 * a segment matches a rule when its own catalog product is one the rule watches and its own zones
 * and polygon satisfy the rule's matching mode, so every geography method below keeps taking an
 * IngestedProduct and none of them knows the difference. A rule with any matching segment gets ONE
 * delivery per channel, carrying every matching segment id; its matched location is the matching
 * location with the lowest id. A bulletin without segments (non-VTEC products, factory rows) is
 * matched on its own columns, exactly as before.
```

3. Replace `__invoke()` (everything from `public function __invoke` through its closing brace) with:

```php
    public function __invoke(IngestedProduct $product): void
    {
        $segments = $product->segments()->get();

        $productIds = $segments->isEmpty()
            ? array_values(array_filter([$product->product_id]))
            : $segments->pluck('product_id')->filter()->unique()->values()->all();

        if ($productIds === []) {
            return;
        }

        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', self::PRODUCT_MATCHED_CATEGORIES)
                ->whereHas('products', fn (Builder $q) => $q->whereKey($productIds))
                ->when($segments->isNotEmpty(), fn (Builder $query) => $query->with('products'))
                ->when($product->is_test, fn (Builder $query) => $query->whereHas('user', fn (Builder $q) => $q->where('is_test_account', true)))
                ->get();
        } catch (Throwable $e) {
            logger()->error('Failed to query matching AlertRules', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (AlertRule $rule) use ($product, $segments): void {
            if ($segments->isEmpty()) {
                $match = $this->matchesGeographySafely($rule, $product);

                if ($match->matched && $this->matchesRiskLevel($rule, $product)) {
                    $this->dispatchOrSuppress($rule, $product, $match->location);
                }

                return;
            }

            [$segmentIds, $location] = $this->matchingSegments($rule, $product, $segments);

            if ($segmentIds !== []) {
                $this->dispatchOrSuppress($rule, $product, $location, $segmentIds);
            }
        });
    }

    /**
     * The segments of this bulletin a rule matches, in bulletin order, and the matching location
     * with the lowest id among them (null for a rule with no locations to check). A segment counts
     * when its own catalog product is one the rule watches and its own geography passes the rule's
     * mode and risk-level filter; each is checked through asSegment()'s copy of the bulletin.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments
     * @return array{0: list<int>, 1: ?Location}
     */
    private function matchingSegments(AlertRule $rule, IngestedProduct $product, Collection $segments): array
    {
        $watched = $rule->products->modelKeys();
        $segmentIds = [];
        $location = null;

        foreach ($segments as $segment) {
            if (! in_array($segment->product_id, $watched, true)) {
                continue;
            }

            $view = $product->asSegment($segment);
            $match = $this->matchesGeographySafely($rule, $view);

            if (! $match->matched || ! $this->matchesRiskLevel($rule, $view)) {
                continue;
            }

            $segmentIds[] = $segment->id;

            if ($match->location !== null && ($location === null || $match->location->id < $location->id)) {
                $location = $match->location;
            }
        }

        return [$segmentIds, $location];
    }
```

4. Replace `dispatchOrSuppress()` with the version below (the signature gains `$segmentIds`; the subject names the matched events; the suppressed row links them; the job carries them):

```php
    /**
     * @param  list<int>  $segmentIds  the matched IngestedProductSegment ids; empty for a bulletin without segments
     */
    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product, ?Location $matchedLocation, array $segmentIds = []): void
    {
        $suppressed = $this->isWithinQuietHours($rule, $matchedLocation);
        $segments = $product->matchedSegments($segmentIds);
        $eventNames = $product->eventNamesFor($segments);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed, $matchedLocation, $segmentIds, $segments, $eventNames): void {
            try {
                // A backfilled SPC Convective Outlook row (see
                // App\Actions\Pipeline\BackfillOutlookRiskAreas) gets
                // re-matched once PTSDY1 enriches it with real risk-level
                // geometry -- every other product is only ever matched
                // once, so this is a no-op query there. Guards against a
                // rematch re-delivering (or re-logging a repeat "suppressed"
                // row for) a rule/channel/product combination that already
                // got a real DeliveryLog row on an earlier pass.
                if (DeliveryLog::where('alert_rule_id', $rule->id)
                    ->where('notification_channel_id', $channel->id)
                    ->where('ingested_product_id', $product->id)
                    ->exists()) {
                    return;
                }

                if ($suppressed) {
                    $log = DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'category' => $rule->category->value,
                        'subject' => app(RenderAlertSubject::class)($rule, $eventNames, $matchedLocation),
                        'ingested_product_id' => $product->id,
                        'dispatched_at' => now(),
                    ]);

                    if ($segments->isNotEmpty()) {
                        $log->segments()->attach($segments->modelKeys());
                    }

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, $matchedLocation?->id, $segmentIds);
            } catch (Throwable $e) {
                logger()->error('Failed to dispatch DeliverWeatherAlert', [
                    'rule_id' => $rule->id,
                    'channel_id' => $channel->id,
                    'ingested_product_id' => $product->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/BulletinSegmentMatchingTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/SyncActiveWwaTest.php tests/Feature/BackfillOutlookRiskAreasTest.php tests/Feature/CapIngestionIntegrationTest.php tests/Feature/ReplayIngestedProductTest.php tests/Feature/IngestProductTextTest.php tests/Feature/TestProductGeneratorEndToEndTest.php'`
Expected: PASS. Every existing matcher test passes untouched: those bulletins have no segments, or a fallback segment that mirrors their columns.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/BulletinSegmentMatchingTest.php'
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/BulletinSegmentMatchingTest.php
git commit -m "Matching: check a VTEC bulletin section by section, not only its first zone block and VTEC line

A rule now matches an event in any section (Destin's own zone in the Hurricane Local
Statement) and gets one delivery naming every matched event.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: "Alerted" evidence in Active now follows the events a delivery covered

**Files:**
- Modify: `app/Actions/Alerts/GetActiveNowAtMyLocations.php`
- Test: `tests/Feature/GetActiveNowAtMyLocationsTest.php`

**Interfaces:**
- Consumes (Tasks 2, 5): `DeliveryLog::segments()`, `IngestedProductSegment`.
- Produces: an active event reads "alerted"/"held"/"failed" from a delivery that covered it through `delivery_log_segment`; deliveries logged before segments existed (no links) keep the existing lookup through the bulletin's VTEC columns.

A delivery that has links covers exactly the events it linked: a delivery for the second event of a bulletin must show that event as alerted, and must not show the bulletin's first VTEC event as alerted just because its columns say so.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/GetActiveNowAtMyLocationsTest.php` add `use App\Models\IngestedProductSegment;` to the imports and append:

```php
test('a delivery that covered the event as a later section of its bulletin counts as alerted', function () {
    $wwa = activeNowWarning();
    $rule = activeNowRule($this->user, 'Gulf rule');
    // The bulletin's own first VTEC line is a different event; the delivery covered this one through a segment.
    $bulletin = IngestedProduct::factory()->createQuietly([
        'vtec_office' => 'KBOU', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0001', 'vtec_year' => 2026, 'is_test' => false,
    ]);
    $segment = IngestedProductSegment::factory()->for($bulletin)->create(ActiveWwa::vtecKeyFor($wwa));
    DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => $bulletin->id, 'alert_rule_id' => $rule->id, 'status' => 'success', 'dispatched_at' => now()->subMinutes(30),
    ])->segments()->attach($segment);

    $event = (new GetActiveNowAtMyLocations)($this->user)['events'][0];

    expect($event['status'])->toBe('alerted')
        ->and($event['alerted_via'])->toBe(['Gulf rule']);
});

test('a delivery linked only to other events of its bulletin does not count for an event the bulletin\'s first VTEC line names', function () {
    $wwa = activeNowWarning();
    $bulletin = IngestedProduct::factory()->createQuietly([...ActiveWwa::vtecKeyFor($wwa), 'is_test' => false]);
    $otherEvent = IngestedProductSegment::factory()->for($bulletin)->create([...ActiveWwa::vtecKeyFor($wwa), 'vtec_event_number' => '0999']);
    DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => $bulletin->id, 'status' => 'success', 'dispatched_at' => now()->subMinutes(30),
    ])->segments()->attach($otherEvent);

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('a delivery with no linked events still counts through its bulletin\'s VTEC columns', function () {
    $wwa = activeNowWarning();
    activeNowDelivery($this->user, $wwa, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('alerted');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/GetActiveNowAtMyLocationsTest.php'`
Expected: FAIL on the first two tests (the lookup goes only through the bulletin's VTEC columns); the third already passes.

- [ ] **Step 3: Look up the evidence through the linked events**

In `app/Actions/Alerts/GetActiveNowAtMyLocations.php` add `use Illuminate\Database\Eloquent\Builder;`. Extend the class docblock paragraph on coverage with: `A delivery that has linked events (delivery_log_segment) covers exactly those events; a delivery logged before segments existed has none and is matched through its bulletin's vtec_* columns.` In `eventRow()` replace the line `->whereIn('ingested_product_id', IngestedProduct::query()->select('id')->where(ActiveWwa::vtecKeyFor($wwa))->where('is_test', false))` with:

```php
            ->where(function (Builder $query) use ($wwa): void {
                $query->whereHas('segments', function (Builder $segments) use ($wwa): void {
                    $segments->where(ActiveWwa::vtecKeyFor($wwa))
                        ->whereHas('ingestedProduct', fn (Builder $bulletin) => $bulletin->where('is_test', false));
                })->orWhere(function (Builder $unlinked) use ($wwa): void {
                    $unlinked->doesntHave('segments')
                        ->whereIn('ingested_product_id', IngestedProduct::query()->select('id')->where(ActiveWwa::vtecKeyFor($wwa))->where('is_test', false));
                });
            })
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/GetActiveNowAtMyLocationsTest.php'`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Alerts/GetActiveNowAtMyLocations.php tests/Feature/GetActiveNowAtMyLocationsTest.php'
git add app/Actions/Alerts/GetActiveNowAtMyLocations.php tests/Feature/GetActiveNowAtMyLocationsTest.php
git commit -m "Active now: read the alerted evidence from the events a delivery covered

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Record the rules, then verify the whole change

**Files:**
- Modify: `.ai/rules/pipeline.md`

- [ ] **Step 1: Record the pipeline rule**

Append this section to `.ai/rules/pipeline.md`:

```markdown

## A bulletin is a list of sections; rules match its segments, never its first zone block or VTEC line
A VTEC text bulletin is several sections separated by `$$`, each with its own zone line, one or more VTEC lines and polygon, but `ingested_products` keeps only the FIRST zone block and FIRST VTEC line, so a bulletin's own `ugc_codes`, `product_id` and `vtec_*` describe its first section only. `BuildBulletinSegments` (the first step of `ProcessIngestedProduct::handle()`, for every bulletin with `vtec_office`, replays included) stores one `ingested_product_segments` row per (section x VTEC line), and `MatchWeatherAlertRules` checks a rule segment by segment through `IngestedProduct::asSegment()`. A rule with any matching segment gets one delivery per channel carrying all its segment ids (`delivery_log_segment` records them; the subject names each event once, joined with " + "). Build the segments in the job, never in `IngestProductText`: the observer queues the job from the model's created event, before the ingest action returns. Never decide whether a rule matches a VTEC bulletin from its own `ugc_codes` or `product_id` when it has segments. The segment-less path (non-VTEC products, factory rows) must keep working, and a text bulletin whose sections cannot be read falls back to one segment copied from its columns. `SyncActiveWwa::syncFromCap()` still skips matching a CAP message that has a paired text bulletin; that is safe only because the text bulletin's segments are complete, so a parser regression that drops sections silently drops alerts. `ParseUgcLine` must accept a range on a full code (`CAZ110>114`), wrapped zone lines and a prefix change inside a line: 8.5% of stored bulletins once yielded no zones at all because it did not.
```

- [ ] **Step 2: Run the affected tests**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ParseUgcLineTest.php tests/Feature/ParseLatLonPolygonTest.php tests/Feature/ParseVtecStringTest.php tests/Feature/ExtractProductMetadataTest.php tests/Feature/IngestedProductSegmentTest.php tests/Feature/ParseBulletinSegmentsTest.php tests/Feature/BuildBulletinSegmentsTest.php tests/Feature/ProcessIngestedProductTest.php tests/Feature/ParseStoredBulletinSegmentsTest.php tests/Feature/RenderAlertSubjectTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/BulletinSegmentMatchingTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/FactoryResetActionsTest.php'`
Expected: PASS.

- [ ] **Step 3: Run the whole suite in parallel**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`
Expected: PASS (about 3 minutes). A failure in a test this plan did not touch is a regression to investigate, not to skip: the likely cause is a test that creates a VTEC `IngestedProduct` with the observer on and now also gets a fallback segment.

- [ ] **Step 4: Commit the rule**

```bash
git add .ai/rules/pipeline.md
git commit -m "Docs: record that rules match a bulletin's segments, not its first section

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Hand the deployment steps to the owner (do not run them)**

Report these in the final message; the owner runs them:

1. `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan migrate'` (creates the two tables).
2. `docker restart wxnotify-pipeline-api wxnotify-nextgen-front-end-1` (the ingest container runs PHP-FPM and keeps the old code until restarted; the queue worker reloads per job).
3. `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan bulletins:parse-segments --days=2'` once, so the events active now have segments. It sends nothing.

---

## Self-review (spec coverage)

| Spec requirement | Task |
| --- | --- |
| `ingested_product_segments` table, model, relations, `vtecKey` use | 2 |
| `delivery_log_segment` pivot | 2 |
| `ingested_products` unchanged | all (no migration touches it) |
| `BuildBulletinSegments` first in `ProcessIngestedProduct`, every VTEC bulletin, idempotent | 3, 4 |
| `ParseBulletinSegments` (split on `$$`, all VTEC lines, polygon, product per line) | 3 |
| Fallback to one segment; CAP gets one segment | 3 |
| Shared `ParseUgcLine` (full-code ranges, wrapped lines, prefix switches), shared polygon and VTEC readers | 1 |
| Matching per segment in position order; segment's own zones and polygon; all modes; risk filter, replay, test routing, quiet hours, duplicate guard unchanged | 6 |
| One delivery per rule/channel carrying all matched segment ids; first matching location by id | 6 |
| CAP-with-text-pair skip kept; CAP alone matches through its segment | 6 (no change to `SyncActiveWwa`; CAP test) |
| `DeliverWeatherAlert` takes `segmentIds`; joined subject; named rule keeps name; `{product}` joined; `RenderAlertSubject` takes names | 5 |
| `ComposeTemplatedAlertBody` reads the first matched segment's event | 5 |
| `delivery_log_segment` written for success, failure, suppressed and skipped rows | 5, 6 |
| `GetActiveNowAtMyLocations` evidence via the pivot with legacy fallback | 7 |
| `bulletins:parse-segments {--days=2}` sends nothing | 4 |
| `WipePipelineData`/`PreviewFactoryReset` include the new tables | 2 |
| Fixtures from real stored bulletins (TCV, MWW with HU.A+SC.Y, NPW, CFW) | committed with this plan; used in 1, 3, 4, 6 |
| Rule recorded in `.ai/rules/pipeline.md` | 8 |
| Out of scope respected (non-VTEC multi-section products, per-section narrative, per-location notifications, project 2) | not touched |

# Narrative Section Parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the HAZARD/SOURCE/IMPACT bullets, a human-readable "locations impacted" line, and a call-to-action section from a VTEC-class product's narrative text at ingest time, closing the one remaining gap (everything else — polygon/timing/zones/motion/event/severity — is already covered by CAP ingestion and `active_wwas`) before the master plan's Custom Alert Templates section-picker becomes buildable.

**Architecture:** One new shared action, `ParseNarrativeSections`, that scans a block of narrative prose (the raw text bulletin body, or CAP's already-extracted `description`) for labeled sections and returns them as a named array — never guessing, returning `null` for anything it can't confidently locate. Five new nullable columns on `ingested_products` store the result. `ExtractProductMetadata` (text rows) uses all five fields as-is; `ExtractCapMetadata` (CAP rows) uses four of them from `description`, but overrides `call_to_action` with CAP's own already-structured `instruction` field instead of re-parsing it.

**Tech Stack:** Laravel 13, PHP 8.4, Pest.

**Design doc:** `docs/superpowers/specs/2026-09-15-narrative-section-parser-design.md` — read it first for the full rationale, especially why `locations_impacted`/`call_to_action` are explicitly best-effort (real NWS bulletins are not perfectly standardized across offices/products) rather than fully-solved.

**Scope note:** This plan covers the parser and its storage only — the subscriber-facing template-builder UI and wiring these fields into `DeliverWeatherAlert`'s body rendering are separate, later pieces, matching how CAP ingestion was itself built (parser first, consumer later).

**Fixture honesty note:** The HAZARD/SOURCE/IMPACT fixture text below is the same real, verbatim-captured Flash Flood Warning used throughout the CAP ingestion plan (`docs/superpowers/plans/2026-09-14-active-wwa-tracking.md`), confirmed against live NWWS traffic. The `locations_impacted`/`call_to_action` fixture is **not** a verbatim historical capture — it's a realistic construction following well-documented, standard NWS severe-weather-warning bulletin conventions (the `PRECAUTIONARY/PREPAREDNESS ACTIONS...` / `&&` / `LAT...LON` structure is a widely-standardized NWS convention, independently confirmed elsewhere in this codebase's own `ExtractProductMetadata::parsePolygon()` docblock and test fixtures). Each task below states plainly which fixtures are real captures and which are realistic constructions — don't blur this distinction when extending the test suite later.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/..._add_narrative_sections_to_ingested_products_table.php` | New columns: `hazard`, `hazard_source`, `impact`, `locations_impacted`, `call_to_action` |
| `app/Models/IngestedProduct.php` | Modify: Fillable for the 5 new columns |
| `app/Actions/Pipeline/ParseNarrativeSections.php` | New: extracts the 5 fields from a block of narrative text |
| `app/Actions/Pipeline/ExtractProductMetadata.php` | Modify: wire in `ParseNarrativeSections` against the raw text |
| `app/Actions/Pipeline/ExtractCapMetadata.php` | Modify: wire in `ParseNarrativeSections` against `description`; override `call_to_action` with `instruction` |

---

## Task 1: Schema — narrative section columns on `ingested_products`

**Files:**
- Create: `database/migrations/2026_09_15_010000_add_narrative_sections_to_ingested_products_table.php`
- Modify: `app/Models/IngestedProduct.php`

No failing-test step — schema and model plumbing only, verified via a real migration run and a Tinker round-trip, matching this codebase's own established convention for schema-only tasks.

- [ ] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration add_narrative_sections_to_ingested_products_table --no-interaction"
```

Rename the generated file to exactly `database/migrations/2026_09_15_010000_add_narrative_sections_to_ingested_products_table.php`, then set its content to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->text('hazard')->nullable()->after('wmo_identifier');
            // The bulletin's own SOURCE...bullet (e.g. "Radar indicated") --
            // named hazard_source, not source, specifically to avoid
            // confusion with active_wwas.source (an unrelated text/cap
            // provenance marker on a different table).
            $table->text('hazard_source')->nullable()->after('hazard');
            $table->text('impact')->nullable()->after('hazard_source');
            $table->text('locations_impacted')->nullable()->after('impact');
            $table->text('call_to_action')->nullable()->after('locations_impacted');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn(['hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action']);
        });
    }
};
```

- [ ] **Step 2: Update the `IngestedProduct` model**

Read `app/Models/IngestedProduct.php` first. Change the `#[Fillable]` attribute from:

```php
#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
])]
```

to:

```php
#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
    'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action',
])]
```

No cast changes needed — all 5 new columns are plain nullable strings.

- [ ] **Step 3: Run the migration and verify with Tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: runs with no errors.

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$p = App\Models\IngestedProduct::factory()->create([
    \"hazard\" => \"Flash flooding caused by thunderstorms.\",
    \"hazard_source\" => \"Radar indicated.\",
    \"impact\" => \"Flash flooding of small creeks and streams.\",
]);
echo \$p->hazard . \" | \" . \$p->hazard_source . \" | \" . \$p->impact . PHP_EOL;
echo var_export(\$p->locations_impacted, true) . PHP_EOL;
'"
```

Expected: prints the three set values on the first line, then `NULL` on the second (confirms unset columns default to null).

- [ ] **Step 4: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_15_010000_add_narrative_sections_to_ingested_products_table.php app/Models/IngestedProduct.php
git commit -m "$(cat <<'EOF'
Add narrative section columns to ingested_products

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `ParseNarrativeSections` action

**Files:**
- Create: `app/Actions/Pipeline/ParseNarrativeSections.php`
- Test: `tests/Feature/ParseNarrativeSectionsTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Pipeline\ParseNarrativeSections;

/**
 * A real, verbatim Flash Flood Warning bulletin body -- the same fixture
 * used throughout docs/superpowers/plans/2026-09-14-active-wwa-tracking.md,
 * confirmed against live captured NWWS traffic. Has HAZARD/SOURCE/IMPACT
 * bullets but no "locations impacted" line and no
 * PRECAUTIONARY/PREPAREDNESS ACTIONS section.
 */
function realFfwBulletinText(): string
{
    return <<<'TEXT'
    WGUS55 KTWC 142354
    FFWTWC

    BULLETIN - EAS ACTIVATION REQUESTED
    Flash Flood Warning
    National Weather Service Tucson AZ
    454 PM MST Mon Sep 14 2026

    AZC021-142100-
    /O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/

    The National Weather Service in Tucson has issued a

    * Flash Flood Warning for...
    Southeastern Pinal County in southeastern Arizona...

    * Until 800 PM MST.

    * At 454 PM MST, Doppler radar indicated thunderstorms producing
    heavy rain across the warned area. Flash flooding is ongoing or
    expected to begin shortly.

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams, urban areas,
    highways, streets and underpasses as well as other poor
    drainage and low-lying areas.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;
}

/**
 * NOT a verbatim historical capture -- a realistic construction following
 * standard, well-documented NWS severe-weather-warning bulletin
 * conventions (the PRECAUTIONARY/PREPAREDNESS ACTIONS.../&&/LAT...LON
 * structure is independently confirmed elsewhere in this codebase, e.g.
 * ExtractProductMetadata::parsePolygon()'s own docblock and fixtures).
 * Used specifically to exercise the locations_impacted/call_to_action
 * paths, which realFfwBulletinText() above doesn't contain.
 */
function realisticSevereThunderstormBulletinText(): string
{
    return <<<'TEXT'
    HAZARD...60 mph wind gusts and quarter size hail.

    SOURCE...Radar indicated.

    IMPACT...Expect damage to roofs, siding, and trees.

    Locations impacted include...
    Springfield, Right City and Millbrook.

    PRECAUTIONARY/PREPAREDNESS ACTIONS...

    For your protection move to an interior room on the lowest floor of a
    building.

    &&

    LAT...LON 4046 10066 4050 10070 4048 10040
    TEXT;
}

test('extracts hazard, source, and impact from a real bulletin, normalized to single lines', function () {
    $result = (new ParseNarrativeSections)(realFfwBulletinText());

    expect($result['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($result['hazard_source'])->toBe('Radar indicated.')
        ->and($result['impact'])->toBe('Flash flooding of small creeks and streams, urban areas, highways, streets and underpasses as well as other poor drainage and low-lying areas.');
});

test('a bulletin with no locations-impacted or call-to-action section returns null for both, not a wrong guess', function () {
    $result = (new ParseNarrativeSections)(realFfwBulletinText());

    expect($result['locations_impacted'])->toBeNull()
        ->and($result['call_to_action'])->toBeNull();
});

test('extracts locations_impacted and call_to_action, stopping at the && delimiter', function () {
    $result = (new ParseNarrativeSections)(realisticSevereThunderstormBulletinText());

    expect($result['locations_impacted'])->toBe('Springfield, Right City and Millbrook.')
        ->and($result['call_to_action'])->toBe('For your protection move to an interior room on the lowest floor of a building.');
});

test('a blank line immediately after a label does not produce an empty result', function () {
    // PRECAUTIONARY/PREPAREDNESS ACTIONS... conventionally has a blank line
    // before its body starts, unlike HAZARD/SOURCE/IMPACT which put content
    // on the same "paragraph". A naive "stop at the first blank line" parser
    // would incorrectly capture nothing here.
    $result = (new ParseNarrativeSections)(realisticSevereThunderstormBulletinText());

    expect($result['call_to_action'])->not->toBeNull()
        ->and($result['call_to_action'])->not->toBe('');
});

test('a product with no VTEC narrative structure at all returns all five fields as null', function () {
    $result = (new ParseNarrativeSections)("AREA FORECAST DISCUSSION\nJust ordinary prose, no labeled sections.");

    expect($result)->toBe([
        'hazard' => null,
        'hazard_source' => null,
        'impact' => null,
        'locations_impacted' => null,
        'call_to_action' => null,
    ]);
});

test('an empty string input returns all five fields as null', function () {
    $result = (new ParseNarrativeSections)('');

    expect($result)->toBe([
        'hazard' => null,
        'hazard_source' => null,
        'impact' => null,
        'locations_impacted' => null,
        'call_to_action' => null,
    ]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ParseNarrativeSectionsTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Pipeline;

/**
 * Extracts the HAZARD/SOURCE/IMPACT bullets, a "locations impacted" line,
 * and a call-to-action section from a VTEC-class product's narrative text
 * -- the raw text bulletin body for a text-sourced IngestedProduct, or
 * CAP's <description> for a CAP-sourced one (both use the identical bullet
 * format; CAP does not pre-split this content -- see ExtractCapMetadata).
 *
 * Never guesses: a section this class can't confidently locate comes back
 * null. locations_impacted and call_to_action in particular only recognize
 * one real, standard NWS phrasing each ("Locations impacted include..." and
 * "PRECAUTIONARY/PREPAREDNESS ACTIONS...") -- a real bulletin using
 * different phrasing for either (this happens; NWS bulletins are not
 * perfectly standardized across offices/products) will legitimately return
 * null for that field. This is a known, accepted coverage gap, not a bug
 * to chase down -- see the design doc.
 */
class ParseNarrativeSections
{
    /**
     * @return array{hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $narrativeText): array
    {
        return [
            'hazard' => $this->extractSection($narrativeText, 'HAZARD'),
            'hazard_source' => $this->extractSection($narrativeText, 'SOURCE'),
            'impact' => $this->extractSection($narrativeText, 'IMPACT'),
            'locations_impacted' => $this->extractSection($narrativeText, 'Locations impacted include'),
            'call_to_action' => $this->extractSection($narrativeText, 'PRECAUTIONARY/PREPAREDNESS ACTIONS'),
        ];
    }

    /**
     * Finds a "$label..." header at the start of a line and returns
     * everything between it and the next section boundary, normalized to a
     * single line (real bulletins hard-wrap section bodies at ~70
     * characters). The boundary is the next recognized "LABEL..." header,
     * the "&&" end-of-narrative delimiter, or the end of the text --
     * whichever comes first.
     *
     * Deliberately does NOT stop at the first blank line: some sections
     * (PRECAUTIONARY/PREPAREDNESS ACTIONS in particular) conventionally put
     * a blank line between the label and its body, which a naive
     * first-blank-line stop would misread as "no content".
     */
    private function extractSection(string $text, string $label): ?string
    {
        $startPattern = '/^'.preg_quote($label, '/').'\.\.\./m';

        if (! preg_match($startPattern, $text, $startMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $contentStart = $startMatch[0][1] + strlen($startMatch[0][0]);
        $remaining = substr($text, $contentStart);

        if (preg_match('/\n(?:[A-Z][A-Z \/]{2,}\.\.\.|&&)/', $remaining, $endMatch, PREG_OFFSET_CAPTURE)) {
            $remaining = substr($remaining, 0, $endMatch[0][1]);
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $remaining));

        return $normalized === '' ? null : $normalized;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ParseNarrativeSectionsTest.php --compact"
```

Expected: PASS (6 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ParseNarrativeSections.php tests/Feature/ParseNarrativeSectionsTest.php
git commit -m "$(cat <<'EOF'
Add ParseNarrativeSections action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Wire `ParseNarrativeSections` into `ExtractProductMetadata`

**Files:**
- Modify: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Test: `tests/Feature/ExtractProductMetadataTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ExtractProductMetadataTest.php`. Reuse the `realFfwBulletinText()` helper from `tests/Feature/ParseNarrativeSectionsTest.php` is NOT possible across files without a shared include (Pest loads each file's top-level functions independently) — inline the fixture text directly instead:

```php
test('populates the narrative section fields from a real VTEC warning bulletin', function () {
    $text = <<<'TEXT'
    WGUS55 KTWC 142354
    FFWTWC

    BULLETIN - EAS ACTIVATION REQUESTED
    Flash Flood Warning
    National Weather Service Tucson AZ
    454 PM MST Mon Sep 14 2026

    AZC021-142100-
    /O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'ffwtwc');

    expect($metadata['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($metadata['hazard_source'])->toBe('Radar indicated.')
        ->and($metadata['impact'])->toBe('Flash flooding of small creeks and streams.')
        ->and($metadata['locations_impacted'])->toBeNull()
        ->and($metadata['call_to_action'])->toBeNull();
});

test('a product with no narrative sections leaves all five fields null', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo labeled sections here.", 'afdokx');

    expect($metadata['hazard'])->toBeNull()
        ->and($metadata['hazard_source'])->toBeNull()
        ->and($metadata['impact'])->toBeNull()
        ->and($metadata['locations_impacted'])->toBeNull()
        ->and($metadata['call_to_action'])->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php --compact"
```

Expected: the 2 new tests FAIL (the keys don't exist in the returned array yet); all 23 pre-existing tests still PASS.

- [ ] **Step 3: Wire `ParseNarrativeSections` into `ExtractProductMetadata`**

Read `app/Actions/Pipeline/ExtractProductMetadata.php` in full first. Change the `__invoke` method from:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        [$productId, $code] = $this->resolveProduct($text, $awipsid);
        $vtec = (new ParseVtecString)($text);

        return [
            'product_id' => $productId,
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $this->parsePolygon($text),
            'risk_levels' => $code === self::CONVECTIVE_OUTLOOK_CODE ? $this->extractConvectiveOutlookRiskLevels($text) : null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
        ];
    }
```

to:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        [$productId, $code] = $this->resolveProduct($text, $awipsid);
        $vtec = (new ParseVtecString)($text);
        $narrative = (new ParseNarrativeSections)($text);

        return [
            'product_id' => $productId,
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $this->parsePolygon($text),
            'risk_levels' => $code === self::CONVECTIVE_OUTLOOK_CODE ? $this->extractConvectiveOutlookRiskLevels($text) : null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
            'hazard' => $narrative['hazard'],
            'hazard_source' => $narrative['hazard_source'],
            'impact' => $narrative['impact'],
            'locations_impacted' => $narrative['locations_impacted'],
            'call_to_action' => $narrative['call_to_action'],
        ];
    }
```

Update the `@return` PHPDoc above `__invoke` to add the 5 new keys, keeping every existing key unchanged:

```php
    /**
     * @return array{product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: ?array<int, string>, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?Carbon, vtec_end: ?Carbon, hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
```

No new `use` import is needed — `ParseNarrativeSections` is in the same `App\Actions\Pipeline` namespace as `ExtractProductMetadata`, same as `ParseVtecString` already is.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php --compact"
```

Expected: PASS, every test in the file (23 pre-existing + 2 new = 25).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "$(cat <<'EOF'
Populate narrative section fields in ExtractProductMetadata

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Wire `ParseNarrativeSections` into `ExtractCapMetadata`

**Files:**
- Modify: `app/Actions/Pipeline/ExtractCapMetadata.php`
- Test: `tests/Feature/ExtractCapMetadataTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ExtractCapMetadataTest.php`. This file already has a `realCapAlertBody()` helper (a real, verbatim-captured FFW CAP payload, confirmed by reading the file directly) whose `<description>` contains `HAZARD...Flash flooding caused by thunderstorms.` / `SOURCE...Radar indicated.` / `IMPACT...Flash flooding of small creeks and streams.` and whose `<instruction>` is exactly `Turn around, don't drown when encountering flooded roads.` — this is the same real event/fixture already used for Task 2's `realFfwBulletinText()` and Task 3's fixture. The existing file also establishes the convention of fetching `FF.W` via `Product::where('code', 'FF.W')->sole()` (it's part of the real seeded catalog, not created via factory — the factory would collide on the unique `code` constraint) — follow that same convention here, matching `tests/Feature/ExtractCapMetadataTest.php:79-84`:

```php
test('populates the narrative section fields from the CAP description, and call_to_action from instruction directly', function () {
    Product::where('code', 'FF.W')->sole();
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($metadata['hazard_source'])->toBe('Radar indicated.')
        ->and($metadata['impact'])->toBe('Flash flooding of small creeks and streams.')
        ->and($metadata['call_to_action'])->toBe("Turn around, don't drown when encountering flooded roads.");
});
```

Also add this test proving the override behavior specifically — that `call_to_action` comes from `instruction`, NOT from re-parsing `description`, even when `description` happens to also contain something matching the `PRECAUTIONARY/PREPAREDNESS ACTIONS...` label:

```php
test('call_to_action always comes from the CAP instruction field, never re-parsed from description', function () {
    Product::where('code', 'SV.W')->sole();
    $body = <<<'TEXT'
    479

    XOUS53 KWBC 142354

    CAPOAX

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <msgType>Alert</msgType>
        <info>
            <event>Severe Thunderstorm Warning</event>
            <description>HAZARD...60 mph wind gusts.

    SOURCE...Radar indicated.

    IMPACT...Expect damage to roofs and trees.

    PRECAUTIONARY/PREPAREDNESS ACTIONS...

    This text must NOT end up as call_to_action -- it should come from
    instruction below instead.

    &&
    </description>
            <instruction>The real instruction text from CAP's own field.</instruction>
            <parameter><valueName>VTEC</valueName><value>/O.NEW.KOAX.SV.W.0500.260906T1800Z-260906T1900Z/</value></parameter>
        </info>
    </alert>
    TEXT;

    $metadata = (new ExtractCapMetadata)($body);

    expect($metadata['call_to_action'])->toBe("The real instruction text from CAP's own field.");
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractCapMetadataTest.php --compact"
```

Expected: the 2 new tests FAIL (the keys don't exist in the returned array yet); all 7 pre-existing tests still PASS.

- [ ] **Step 3: Wire `ParseNarrativeSections` into `ExtractCapMetadata`**

Read `app/Actions/Pipeline/ExtractCapMetadata.php` in full first. Change the `__invoke` method from:

```php
    public function __invoke(string $text): array
    {
        $xmlStart = strpos($text, '<?xml');
        $xml = new SimpleXMLElement($xmlStart !== false ? substr($text, $xmlStart) : $text);
        $info = $xml->info;

        $parameters = $this->collectParameters($info);
        $vtecString = $parameters['VTEC'] ?? null;
        $vtec = $vtecString !== null ? (new ParseVtecString)($vtecString) : null;

        $productId = null;
        if ($vtec !== null) {
            $product = Product::where('code', "{$vtec['phenomenon']}.{$vtec['significance']}")->first();
            $productId = $product?->id;
        }

        $area = isset($info->area) ? $info->area : null;

        return [
            'format' => 'cap',
            'msg_type' => (string) $xml->msgType ?: null,
            'event' => (string) $info->event ?: null,
            'severity' => (string) $info->severity ?: null,
            'urgency' => (string) $info->urgency ?: null,
            'certainty' => (string) $info->certainty ?: null,
            'headline' => (string) $info->headline ?: null,
            'description' => (string) $info->description ?: null,
            'instruction' => (string) $info->instruction ?: null,
            'event_motion_description' => $parameters['eventMotionDescription'] ?? null,
            'awips_identifier' => $parameters['AWIPSidentifier'] ?? null,
            'wmo_identifier' => $parameters['WMOidentifier'] ?? null,
            'product_id' => $productId,
            'ugc_codes' => $area !== null ? $this->extractUgcCodes($area) : null,
            'polygon' => $area !== null ? $this->extractPolygon($area) : null,
            'risk_levels' => null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
        ];
    }
```

to:

```php
    public function __invoke(string $text): array
    {
        $xmlStart = strpos($text, '<?xml');
        $xml = new SimpleXMLElement($xmlStart !== false ? substr($text, $xmlStart) : $text);
        $info = $xml->info;

        $parameters = $this->collectParameters($info);
        $vtecString = $parameters['VTEC'] ?? null;
        $vtec = $vtecString !== null ? (new ParseVtecString)($vtecString) : null;

        $productId = null;
        if ($vtec !== null) {
            $product = Product::where('code', "{$vtec['phenomenon']}.{$vtec['significance']}")->first();
            $productId = $product?->id;
        }

        $area = isset($info->area) ? $info->area : null;
        $description = (string) $info->description ?: null;
        $instruction = (string) $info->instruction ?: null;
        $narrative = (new ParseNarrativeSections)($description ?? '');

        return [
            'format' => 'cap',
            'msg_type' => (string) $xml->msgType ?: null,
            'event' => (string) $info->event ?: null,
            'severity' => (string) $info->severity ?: null,
            'urgency' => (string) $info->urgency ?: null,
            'certainty' => (string) $info->certainty ?: null,
            'headline' => (string) $info->headline ?: null,
            'description' => $description,
            'instruction' => $instruction,
            'event_motion_description' => $parameters['eventMotionDescription'] ?? null,
            'awips_identifier' => $parameters['AWIPSidentifier'] ?? null,
            'wmo_identifier' => $parameters['WMOidentifier'] ?? null,
            'product_id' => $productId,
            'ugc_codes' => $area !== null ? $this->extractUgcCodes($area) : null,
            'polygon' => $area !== null ? $this->extractPolygon($area) : null,
            'risk_levels' => null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
            'hazard' => $narrative['hazard'],
            'hazard_source' => $narrative['hazard_source'],
            'impact' => $narrative['impact'],
            'locations_impacted' => $narrative['locations_impacted'],
            // CAP's own instruction is already reliably structured -- never
            // re-parsed from description's prose for this one field.
            'call_to_action' => $instruction,
        ];
    }
```

Update the `@return` PHPDoc above `__invoke` to add the 5 new keys, keeping every existing key unchanged:

```php
    /**
     * @return array{format: string, msg_type: ?string, event: ?string, severity: ?string, urgency: ?string, certainty: ?string, headline: ?string, description: ?string, instruction: ?string, event_motion_description: ?string, awips_identifier: ?string, wmo_identifier: ?string, product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: null, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?Carbon, vtec_end: ?Carbon, hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
```

No new `use` import needed — same namespace as `ParseVtecString`/`ParseNarrativeSections`.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractCapMetadataTest.php --compact"
```

Expected: PASS, every test in the file (7 pre-existing + 2 new = 9).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ExtractCapMetadata.php tests/Feature/ExtractCapMetadataTest.php
git commit -m "$(cat <<'EOF'
Populate narrative section fields in ExtractCapMetadata

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Full suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, no regressions anywhere in the suite.

- [ ] **Step 2: Run Pint across the whole diff**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

Expected: `{"tool":"pint","result":"passed"}`.

- [ ] **Step 3: Manual verification against real captured data**

Using the same real FFW text (and its paired CAP payload) already used for manual verification in `docs/superpowers/plans/2026-09-14-active-wwa-tracking.md`, POST both through `/api/pipeline/nwws-products` (or via Tinker directly), and confirm the resulting `IngestedProduct` rows have `hazard`/`hazard_source`/`impact` correctly populated on both the text row and the CAP row, and that they agree with each other (since both were extracted from the same real event's narrative content, just via different source fields).

- [ ] **Step 4: Final commit (if step 3 surfaced any fixes)**

```bash
git add -A
git commit -m "Fix issues found during manual verification of the narrative section parser"
```

(Skip this step entirely if step 3 found nothing to fix.)

---

## Self-Review Notes

**Spec coverage:** Every section of the design doc has a corresponding task — the shared `ParseNarrativeSections` action (Task 2), the schema (Task 1), wiring into both extractors including the CAP `instruction`-override behavior (Tasks 3-4), and the explicit locations_impacted/call_to_action coverage-gap honesty (documented in the action's own docblock and tested explicitly in Task 2 Step 1's "no locations-impacted or call-to-action section" test).

**Placeholder scan:** No TBD/TODO markers. Task 4's `call_to_action` assertion was initially drafted as a placeholder pending confirmation of the real fixture text; `tests/Feature/ExtractCapMetadataTest.php` was read directly during plan-writing and the assertion was filled in with the fixture's real, exact `<instruction>` text (`"Turn around, don't drown when encountering flooded roads."`) — no placeholder remains. That same read also surfaced that `FF.W` and `SV.W` are both part of the real seeded product catalog (confirmed via Tinker), so both Task 4 tests fetch the product with `Product::where('code', ...)->sole()` rather than `Product::factory()->create(...)`, matching this file's own established convention and avoiding a unique-constraint collision.

**Type consistency:** `ParseNarrativeSections`'s 5 output keys (`hazard`, `hazard_source`, `impact`, `locations_impacted`, `call_to_action`) are used identically in both `ExtractProductMetadata` and `ExtractCapMetadata`'s wiring, and match the migration's 5 new column names exactly.

**Real-data grounding:** The HAZARD/SOURCE/IMPACT fixture is the same real, verbatim-captured example already used and confirmed throughout the CAP ingestion plan. The locations_impacted/call_to_action fixture is explicitly marked as a realistic construction, not a verbatim capture — this distinction is stated in the plan header and repeated at each fixture's point of use, so no task can be mistaken as claiming broader real-data confirmation than actually exists.

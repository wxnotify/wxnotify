# Multi-Location Weather Alerts / SPC & WPC Alert Rules — Design

Source: subscriber request — "allow a subscriber to select multiple locations" for Weather Alerts and SPC & WPC Alerts specifically (Threshold, Daily Weather Summary, and Lightning are out of scope; they stay single-location).

## Goal

Let a subscriber attach more than one saved `Location` to a single Weather Alerts or SPC & WPC alert rule, with "any attached location matches → the rule fires" semantics, without touching Threshold/Daily Summary/Lightning (which keep today's single `location_id`).

## Decisions (user-approved, from brainstorming)

- **Storage**: new `alert_rule_location` pivot table, mirroring the existing `alert_rule_product`/`alert_rule_notification_channel` pattern exactly (composite PK, `cascadeOnDelete` on both FKs). `AlertRule::location_id`/`location()` are **not removed** — Threshold/DailySummary/Lightning keep using them unchanged. A new `AlertRule::locations(): BelongsToMany` is additive.
- **Matching semantics**: "any matched location fires the rule" — a rule matches if *any* of its attached locations satisfies the mode's geography check (zone overlap, point-in-polygon, risk-area). This mirrors the existing `->each()`-over-channels dispatch shape already in `MatchWeatherAlertRules::dispatchOrSuppress()`.
- **The one piece of new information multi-location needs**: *which* attached location actually caused a given match. `matchesGeography()` changes its return type from `bool` to a small value object, `GeographyMatch` (`matched: bool`, `location: ?Location`), following the same "small result DTO" convention this codebase already uses for `DeliveryResult` (`ChannelDispatcher`). That one piece of information resolves both of the open design questions below with no separate policy:
  - **Quiet hours**: `isWithinQuietHours()` uses the *matched* location's timezone, not an aggregate across every attached location. When there's no matched location (Nationwide/Wfo/CountyZone — unchanged from today), quiet hours never applies, exactly like today's fallback.
  - **Subject rendering**: `RenderAlertSubject`'s `{location}` placeholder renders the location that actually triggered this specific delivery, not an arbitrary location or a joined list.
  - **Named edge case, accepted**: if two attached locations both fall inside the same incoming product's area, the subject shows whichever one the match loop reaches first (attachment order) — not both. The alert still fires correctly either way; only the subject's location name is "first match wins," consistent with how this codebase already accepts similar simplifications (e.g. `matchesRiskArea`'s own fallback chain).
- **Form field**: `locationSelect()` becomes a multi-select (`Select::make('location_ids')->multiple()`), **not** a `CheckboxList`/`ToggleList`. This codebase's own `countyZoneField()`, in the exact same dynamic `Step::schema(fn (Get $get) => ...)` context as `locationSelect()`, already proves `->multiple()` is safe there as long as `options()` is a plain closure (no `->relationship()`, no AJAX-backed entangle) — confirmed in that field's own docblock via real browser testing, not just Pest. The documented entangle bug (`.ai/rules/filament.md`) is about `->relationship()`/AJAX-entangled state, not `->multiple()` itself. This keeps the UI a proper searchable multi-select dropdown rather than a long checkbox list, and is both less code and better UX for a subscriber with many saved locations.
- **Data migration**: existing production `AlertRule` rows with `category IN (weather_alerts, spc_wpc)`, `matching_mode IN (location, polygon, risk_area)`, and `location_id IS NOT NULL` must be backfilled into the new pivot in the same migration that creates the table — otherwise every existing subscriber's Location/Polygon/RiskArea-mode Weather Alerts/SPC & WPC rule silently stops matching anything the moment matching code switches to reading `locations()` instead of `location()`. This is the single highest-risk step in the whole change and must not be treated as optional cleanup.

## Non-goals

- Threshold, Daily Weather Summary, Lightning, and Forecast Discussion categories are untouched — they keep `location_id`/`wfo` exactly as today.
- No UI/matching change to `location_id` itself; it simply stops being read for Weather Alerts/SPC & WPC rules going forward (an edited rule's stale `location_id` is harmless and unread, matching this codebase's existing precedent for other hidden/unused fields after a schema shift — see `.ai/rules/filament.md`'s hidden-field notes).
- `SendActivityAlert` (staff activity alerts) and scheduled-category rendering (`DeliverScheduledAlert`) are untouched; `RenderAlertSubject`'s new location parameter is optional and defaults to today's behavior (`$rule->location`) for every call site that doesn't pass one explicitly.

## Data model

`alert_rule_location` (new pivot, mirrors `alert_rule_product`):
```php
Schema::create('alert_rule_location', function (Blueprint $table) {
    $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
    $table->foreignId('location_id')->constrained()->cascadeOnDelete();
    $table->primary(['alert_rule_id', 'location_id']);
});
```
Same migration backfills: `INSERT INTO alert_rule_location (alert_rule_id, location_id) SELECT id, location_id FROM alert_rules WHERE category IN ('weather_alerts','spc_wpc') AND matching_mode IN ('location','polygon','risk_area') AND location_id IS NOT NULL`.

`AlertRule::locations(): BelongsToMany` — `belongsToMany(Location::class)`, additive alongside the existing `location()`.

## Matching engine (`MatchWeatherAlertRules`)

New small DTO, `App\Support\Geography\GeographyMatch` — `app/Support/Geography/` already exists (`StateTimezones`) and is the established home for geography-domain support classes, parallel to how `DeliveryResult` lives in `App\Support\Delivery` next to `ChannelDispatcher`. Shape: `{matched: bool, location: ?Location}`, with two named constructors (`matched(?Location $location = null)`, `notMatched()`) for readability at each call site.

`matchesGeography(): GeographyMatch` (was `bool`):
- `Nationwide`/`Wfo`/`CountyZone`/`ForecastDiscussion`: unchanged logic, wrapped as `GeographyMatch::matched()` (no location) or `::notMatched()`.
- `Location`/`Polygon`/`RiskArea`: loop `$rule->locations` (not the singular `$rule->location`), return `GeographyMatch::matched($location)` for the first one whose existing per-location check (`matchesLocationZones`/`matchesPolygon`/`matchesRiskArea`, each retargeted to take a `Location $location` parameter instead of reading `$rule->location`) succeeds; `::notMatched()` if none do (or the rule has zero attached locations).

`matchesGeographySafely()`: same try/catch shape, returns `GeographyMatch::notMatched()` on a caught `Throwable` instead of `false`.

`dispatchOrSuppress()`: receives the `GeographyMatch` (or just its `?Location`) from the caller, uses it for both `isWithinQuietHours($rule, $match->location)` and the suppressed-`DeliveryLog`'s `RenderAlertSubject` call.

`isWithinQuietHours(AlertRule $rule, ?Location $matchedLocation)`: reads `$matchedLocation?->timezone` instead of `$rule->location?->timezone`. Null → never suppress, unchanged fallback.

## Delivery and rendering

`DeliverWeatherAlert::__construct` gains a nullable `?int $locationId = null` (its only real call site is `MatchWeatherAlertRules::dispatchOrSuppress()`, confirmed by grep — `DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, $match->location?->id)`).

`RenderAlertSubject::__invoke(AlertRule $rule, ?Product $product = null, ?Location $location = null)`: the `{location}` placeholder uses `$location?->label ?? $rule->location?->label ?? ...` (today's existing fallback chain unchanged) — i.e. an explicit location wins, otherwise fall back to exactly today's behavior. `DeliverWeatherAlert::handle()` resolves `Location::find($this->locationId)` once and passes it to both `RenderAlertSubject` call sites (the "rule deactivated" skip path and the main delivery path). `DeliverScheduledAlert` and `SendActivityAlert` are untouched — they never pass a location, so they keep exactly today's behavior.

## Form (`AlertRuleForm`)

`locationSelect()` → `locationsSelect()`: `Select::make('location_ids')->multiple()->required()->options(fn (): array => auth()->user()->locations()->pluck('label', 'id')->all())`, keeping the same `createOptionForm`/`createOptionAction`/`createOptionUsing`/`helperText` as today's singular field. `matchingModeFields()`'s three `->visible()` calls (Location/Polygon/RiskArea) swap in the new field unchanged otherwise.

`CreateAlertRule::afterCreate()` / `EditAlertRule::afterSave()`: `$this->record->locations()->sync($this->data['location_ids'] ?? [])`, same shape as the existing `products()`/`notificationChannels()` sync calls right next to it.

`EditAlertRule::mutateFormDataBeforeFill()`: `$data['location_ids'] = $this->record->locations->pluck('id')->all()`, same shape as the existing `channel_ids` line right above it.

## Existing test surface this touches

`tests/Feature/MatchWeatherAlertRulesTest.php` has 16 existing tests that create a Location/Polygon/RiskArea-mode `AlertRule` via `'location_id' => $location->id` in the factory's `create()` call — every one of them must switch to `$rule->locations()->attach($location)` after creation (the exact pattern these same tests already use one line later for `$rule->products()->attach(...)` and `$rule->notificationChannels()->attach(...)`). Full list: the quiet-hours suppression test (line 218), both Location-mode tests (284, 298), all three Polygon-mode base tests (344, 364, 384), all four RiskArea-mode tests (398, 423, 446, 469), both fixless-mobile-location tests (493, 507), the per-rule exception-isolation test's `$throwingRule` (524), and all three `active_wwas`-polygon tests (555, 586, 614).

## Testing strategy

- Pivot/relation: a model-level test confirming `AlertRule::locations()` round-trips and the backfill migration inserts the right rows for a representative pre-existing row (seed one before running the migration in a fresh test, or test the backfill SQL directly).
- Matching engine: update the 16 existing tests per the list above (same assertions, new fixture shape — behavior must be unchanged), plus new tests: a rule with two locations in different zones where only the second one overlaps the product still fires; a rule with zero attached locations never fires (replaces an implicit assumption the old single-FK model couldn't even express); quiet hours suppresses/delivers based on whichever location matched, not an unmatched sibling location's timezone (two locations, different timezones, only one's local time is in the quiet window).
- Delivery/rendering: `RenderAlertSubject` unit tests for the new optional `$location` param (explicit location wins, falls back to `$rule->location` when omitted — covers the DailySummary/Threshold call sites staying unchanged). A `DeliverWeatherAlert` test confirming the subject reflects the location passed via the job's new constructor param, not whichever location happens to be first in the rule's attached set.
- Form: `AlertRuleResourceTest.php` additions — create a Weather Alerts rule with 2+ locations, assert both persist via the pivot after `sync()`; edit an existing multi-location rule and confirm the select is pre-filled with all attached locations (`assertFormFieldExists`/`assertSchemaStateSet`, matching this file's existing patterns); the field is still required per mode (Location/Polygon/RiskArea) and still hidden for Nationwide/Wfo/CountyZone, matching today's `->visible()` gating.
- Manual/browser verification is required for the form change specifically — per `.ai/rules/filament.md`'s own documented caution, Pest's `fillForm()` sets Livewire state directly and cannot detect an entangle desync; `countyZoneField()`'s own docblock records that its `->multiple()` safety was confirmed in a real browser, not just by tests, and this change should get the same treatment before being considered done.

## Files touched

- `database/migrations/<ts>_create_alert_rule_location_table.php` (new — schema + backfill)
- `app/Models/AlertRule.php` (add `locations()`)
- `database/factories/AlertRuleFactory.php` (add a `locations()` state helper for tests)
- `app/Support/Geography/GeographyMatch.php` (new DTO)
- `app/Actions/Weather/MatchWeatherAlertRules.php` (`matchesGeography`, `matchesGeographySafely`, `matchesLocationZones`, `matchesPolygon`, `matchesRiskArea`, `isWithinQuietHours`, `dispatchOrSuppress`)
- `app/Actions/Weather/RenderAlertSubject.php` (new optional param)
- `app/Jobs/DeliverWeatherAlert.php` (new constructor param, resolve + thread through)
- `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (`locationSelect()` → multi-select)
- `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `EditAlertRule.php` (sync/hydrate `location_ids`)
- `tests/Feature/MatchWeatherAlertRulesTest.php` (16 existing tests updated, new multi-location tests added)
- `tests/Feature/RenderAlertSubjectTest.php`, `tests/Feature/DeliverWeatherAlertTest.php` (new coverage for the location param)
- `tests/Feature/AlertRuleResourceTest.php` (new form coverage)

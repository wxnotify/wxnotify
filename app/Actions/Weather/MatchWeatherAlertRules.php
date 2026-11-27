<?php

namespace App\Actions\Weather;

use App\Actions\Alerts\CanShowHeldAlertInApp;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Jobs\DeliverWeatherAlert;
use App\Jobs\SendQuietAlertBadge;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Support\Geography\GeographyMatch;
use App\Support\Timezones;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Matches a newly ingested NWWS product against active AlertRules and
 * dispatches DeliverWeatherAlert per matched rule x notification channel.
 * Mirrors App\Actions\Visibility\MatchActivityAlertRules's shape.
 *
 * AlertCategory::SpcWpc shares WeatherAlerts' product-based matching and
 * geography rules (see matchesGeography()); it additionally filters on
 * each product's own risk levels (see matchesRiskLevel()), which WeatherAlerts doesn't have.
 *
 * A VTEC bulletin is a list of sections, each with its own zones, polygon and one or more VTEC
 * events, stored as IngestedProductSegment rows (see BuildBulletinSegments). A bulletin that has
 * segments is matched segment by segment, in bulletin order, through IngestedProduct::asSegment():
 * a segment matches a rule when its own catalog product is one the rule watches and its own zones
 * and polygon satisfy the matching mode the rule holds for that product (its own mode, office, codes
 * and locations, else the rule's: see AlertRule::settingsFor()), so every geography method below keeps taking an
 * IngestedProduct and none of them knows the difference. A rule with any matching segment gets ONE
 * delivery per channel, carrying every matching segment id; its matched location is the matching
 * location with the lowest id among the matching products' own locations. A bulletin without segments (non-VTEC products, factory rows) is
 * matched on its own columns, exactly as before.
 */
class MatchWeatherAlertRules
{
    private const PRODUCT_MATCHED_CATEGORIES = [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc];

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
                ->with([
                    'products',
                    'locations',
                    'productLocations' => fn (BelongsToMany $query) => $query->wherePivotIn('product_id', $productIds),
                ])
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
     * product's matching mode and the rule's risk-level filter; each is checked through asSegment()'s copy of the bulletin.
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

    /**
     * Evaluates matchesGeography() for a single rule in isolation, so a
     * genuinely bad rule/product combination (e.g. malformed polygon data
     * reaching ST_GeomFromText) can't abort matching for every other active
     * rule against this same product — mirrors dispatchOrSuppress()'s
     * per-channel error isolation below, just one level up.
     *
     * The DB::transaction() wrapper isn't just belt-and-suspenders: a raw
     * SQL error (e.g. a Postgres parse error from ST_GeomFromText) leaves
     * the current transaction unusable for any further statement until it's
     * rolled back — a plain try/catch alone catches the PHP exception but
     * would still poison every subsequent rule's queries in this same
     * batch (confirmed directly against this database: a follow-up query
     * inside the same open transaction fails with "current transaction is
     * aborted" after an uncaught SQL error). In production there's no
     * ambient outer transaction around this call today (IngestedProductObserver
     * invokes this synchronously with nothing wrapping it), so this opens a
     * fresh top-level transaction per rule; inside this app's own test
     * suite, which does wrap each test in one, it becomes a SAVEPOINT
     * instead. Either way, a failure here only rolls back this one rule's
     * work, not the batch.
     */
    private function matchesGeographySafely(AlertRule $rule, IngestedProduct $product): GeographyMatch
    {
        try {
            return DB::transaction(fn (): GeographyMatch => $this->matchesGeography($rule, $product));
        } catch (Throwable $e) {
            logger()->error('Failed to evaluate geography match for AlertRule', [
                'rule_id' => $rule->id,
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return GeographyMatch::notMatched();
        }
    }

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
                    $inApp = app(CanShowHeldAlertInApp::class)($channel);

                    $log = DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'category' => $rule->category->value,
                        'subject' => app(RenderAlertSubject::class)($rule, $eventNames, $matchedLocation, $product, $segments),
                        'ingested_product_id' => $product->id,
                        'dispatched_at' => now(),
                        'in_app' => $inApp,
                    ]);

                    if ($segments->isNotEmpty()) {
                        $log->segments()->attach($segments->modelKeys());
                    }

                    // After the row, so the badge counts it. Android gets no push for a held alert.
                    if ($inApp && ($channel->config['platform'] ?? null) === 'ios') {
                        $this->queueQuietBadge($channel);
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

    /**
     * A failure to queue the badge is only logged: the alert is already held and in the app.
     */
    private function queueQuietBadge(NotificationChannel $channel): void
    {
        try {
            SendQuietAlertBadge::dispatch($channel->id);
        } catch (Throwable $e) {
            logger()->warning('Failed to queue the quiet alert badge: '.$e::class, ['channel_id' => $channel->id]);
        }
    }

    /**
     * Matches the product by the settings the rule holds for it (AlertRule::settingsFor()): its own mode,
     * office, county/zone codes and locations, else the rule's when it has none of its own. A product the
     * rule does not watch never matches.
     */
    private function matchesGeography(AlertRule $rule, IngestedProduct $product): GeographyMatch
    {
        $settings = $product->product_id === null ? null : $rule->settingsFor((int) $product->product_id);

        if ($settings === null) {
            return GeographyMatch::notMatched();
        }

        return match ($settings->mode) {
            MatchingMode::Nationwide => GeographyMatch::matched(),
            MatchingMode::Wfo => $this->matchesWfo($settings->wfo, $product->cccc) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::CountyZone => $this->matchesCountyZone($settings->countyZoneCodes, $product) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::Location => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesLocationZones($location, $product) || $this->matchesLocationOffice($rule, $location, $product)),
            MatchingMode::Polygon => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesPolygon($location, $product) ?? ($this->matchesLocationZones($location, $product) || $this->matchesLocationOffice($rule, $location, $product))),
            MatchingMode::RiskArea => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesRiskArea($rule, $location, $product) ?? $this->matchesLocationZones($location, $product)),
            null => GeographyMatch::notMatched(),
        };
    }

    /**
     * "Any of the product's locations matches -> the rule fires for it": the first location, ordered
     * by location id (the relations' explicit orderBy), not attachment order, whose check succeeds wins
     * and is the one downstream code (quiet hours, {location} subject rendering) treats as "the" match
     * for this delivery. A product with zero locations never matches in these three modes -- there is
     * nothing to check.
     *
     * @param  SupportCollection<int, Location>  $locations
     */
    private function matchesAnyLocation(SupportCollection $locations, Closure $matchesLocation): GeographyMatch
    {
        foreach ($locations as $location) {
            if ($matchesLocation($location)) {
                return GeographyMatch::matched($location);
            }
        }

        return GeographyMatch::notMatched();
    }

    /**
     * Only AlertCategory::SpcWpc rules carry a risk_levels filter at all —
     * every other category matches through unaffected (true).
     *
     * An empty/null risk_levels means "every risk level" (the wizard's own
     * helper text: "leave all unchecked to receive every risk level"), so
     * it always matches. When the rule *does* filter, $product->risk_levels
     * carries three possible meanings, and only one of them is a real "no
     * match": null means risk-level extraction doesn't apply to this
     * product's type at all (e.g. the Fire Weather Outlook — see
     * ExtractProductMetadata's "known gap" note), so a rule with a
     * risk_levels filter still matches on product+geography alone rather
     * than being silently, permanently unmatchable; [] means extraction was
     * attempted and found no categorical risk stated today, which is a
     * genuine non-match for any specific-level filter.
     *
     * The levels are the ones the rule selected for THIS product (AlertRule::riskLevelsFor()): each
     * product holds its own, so a level ticked for the Excessive Rainfall Outlook never filters the
     * Day 1 Convective Outlook although both offer "Moderate". Only levels in the product's own
     * `risk_level_options` count, and none means no filter.
     */
    private function matchesRiskLevel(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category !== AlertCategory::SpcWpc || $product->product_id === null) {
            return true;
        }

        $selected = $rule->riskLevelsFor((int) $product->product_id);

        if ($selected === [] || $product->risk_levels === null) {
            return true;
        }

        return array_intersect($selected, $product->risk_levels) !== [];
    }

    /**
     * Takes the specific `Location` being checked (one of the product's
     * possibly several locations), not the rule itself. Returns
     * null (not false) when the product has no polygon, so the caller can
     * fall back to matchesLocationZones() — most VTEC products don't carry a
     * polygon, and a subscriber in Polygon mode shouldn't be silently missed
     * on a warning type that's UGC-only.
     */
    private function matchesPolygon(Location $location, IngestedProduct $product): ?bool
    {
        if (! $location->hasFix()) {
            return null;
        }

        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        if ($product->polygon === null) {
            return $this->matchesActiveWwaPolygon($product, $lng, $lat);
        }

        $wkt = $this->polygonToWkt($product->polygon);

        $result = DB::selectOne(
            'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
            [$wkt, $lng, $lat],
        );

        return $this->resultIsTrue($result);
    }

    /**
     * Read only for a statement that carries no polygon of its own (a continuation like SVS/FFS):
     * a polygon the bulletin or segment does carry is the authority, never the event's older zone
     * polygons (a zone cancelled earlier keeps its old one). The persisted zone polygons are kept
     * current by ApplySegmentToCoverage across an event's whole lifetime; when the statement names
     * zones, only those zones' polygons are consulted, else all of the event's. The location is
     * covered if any such zone row's polygon contains it. Returns null (not false) both
     * when the product isn't VTEC-class at all and when the event has no polygon, so the caller
     * falls back to the row's own polygon field either way. An event with no zone rows at all
     * (a row from before they existed) is read from its own polygon column.
     */
    private function matchesActiveWwaPolygon(IngestedProduct $product, float $lng, float $lat): ?bool
    {
        if ($product->vtec_office === null) {
            return null;
        }

        $activeWwa = ActiveWwa::query()
            ->forProduct($product)
            ->first();

        if ($activeWwa === null) {
            return null;
        }

        $named = array_values(array_unique($product->ugc_codes ?? []));
        $restriction = $named === [] ? '' : ' AND ugc_code IN ('.implode(', ', array_fill(0, count($named), '?')).')';

        $areas = DB::selectOne(
            'SELECT count(*) AS zones, count(polygon) AS polygons, coalesce(bool_or(ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269))), false) AS contains FROM active_wwa_areas WHERE active_wwa_id = ?'.$restriction,
            [$lng, $lat, $activeWwa->id, ...$named],
        );

        if ((int) $areas->zones > 0) {
            return (int) $areas->polygons > 0 ? $this->resultIsTrue($areas) : null;
        }

        $own = DB::selectOne(
            'SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM active_wwas WHERE id = ? AND polygon IS NOT NULL',
            [$lng, $lat, $activeWwa->id],
        );

        return $own === null ? null : $this->resultIsTrue($own);
    }

    /**
     * "At or above" a threshold, not "exactly the checked level(s)": the
     * threshold is the LOWEST-ranked level among the levels the rule selected
     * for this product (AlertRule::riskLevelsFor()) (rank = position in Product::risk_level_options, already
     * ordered low-to-high in the seed data) -- checking "Moderate" also
     * matches a High-risk area, mirroring how these outlooks are actually
     * read. An empty/null risk_levels means "every level" (rank 0), matching
     * matchesRiskLevel()'s own "leave unchecked for every risk level" text.
     *
     * Returns null (not false) -- same contract as matchesPolygon() -- when
     * the triggering product carries no risk_level_polygons at all: every
     * SPC/WPC product except the Convective Outlook's own PTSDY1-enriched
     * row (see App\Actions\Pipeline\BackfillOutlookRiskAreas) has none, so
     * this falls back to matchesPolygon() itself in that case -- which
     * inherits its own fallback to matchesLocationZones() -- rather than
     * silently never matching a rule that mixes a risk-area product with a
     * plain event-type one.
     *
     * Takes the specific location being checked (one of the product's possibly
     * several locations) rather than reading $rule->location.
     */
    private function matchesRiskArea(AlertRule $rule, Location $location, IngestedProduct $product): ?bool
    {
        if ($product->risk_level_polygons === null || $product->risk_level_polygons === []) {
            return $this->matchesPolygon($location, $product);
        }

        if (! $location->hasFix()) {
            return null;
        }

        $order = $product->product?->risk_level_options ?? [];
        $selectedRanks = collect($product->product_id === null ? [] : $rule->riskLevelsFor((int) $product->product_id))
            ->map(fn (string $level): int|false => array_search($level, $order, true))
            ->filter(fn (int|false $rank): bool => $rank !== false);
        $thresholdRank = $selectedRanks->isEmpty() ? 0 : $selectedRanks->min();

        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        foreach ($product->risk_level_polygons as $level => $points) {
            $rank = array_search($level, $order, true);

            if ($rank === false || $rank < $thresholdRank || count($points) < 3) {
                continue;
            }

            $result = DB::selectOne(
                'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
                [$this->polygonToWkt($points), $lng, $lat],
            );

            if ($this->resultIsTrue($result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Not filter_var(..., FILTER_VALIDATE_BOOLEAN): it doesn't recognize
     * Postgres's own 't'/'f' text encoding for a boolean column ('f' is
     * non-empty, so a naive (bool) cast is equally wrong) -- the pgsql PDO
     * driver happens to hand back a native bool today, but a driver or
     * connection-option change silently turning a real match into a dropped
     * alert is the worst failure mode for this system, so both
     * representations are checked explicitly.
     */
    private function resultIsTrue(?object $result): bool
    {
        return $result !== null && ($result->contains === true || $result->contains === 't');
    }

    /**
     * @param  array<int, array{lat: float, lon: float}>  $points
     */
    private function polygonToWkt(array $points): string
    {
        $coords = collect($points)->map(fn (array $p): string => "{$p['lon']} {$p['lat']}");

        if ($coords->first() !== $coords->last()) {
            $coords->push($coords->first()); // WKT polygons must be closed rings
        }

        return 'POLYGON(('.$coords->join(', ').'))';
    }

    private function matchesLocationZones(Location $location, IngestedProduct $product): bool
    {
        if (! $location->hasFix() || $product->ugc_codes === null) {
            return false;
        }

        $location = $this->ensureZonesResolved($location);
        $zones = array_filter([$location->forecast_zone_ugc, $location->county_ugc]);

        return array_intersect($zones, $product->ugc_codes) !== [];
    }

    /**
     * Office-wide Weather Alerts products (Area Forecast Discussion and its kind) carry no UGC codes
     * and no polygon, so a location has nothing to be inside of. They are issued for one NWS office's
     * whole area instead: the product matches a location when its issuing office is the one that
     * covers the location's forecast zone (or county). Applies only to a Weather Alerts rule, only to a
     * product the catalog says has neither UGC codes nor a polygon, and only when this bulletin lists
     * no UGC codes of its own (a bulletin that does is matched by them, never by office). SPC and WPC
     * products come from national centres, so no local office covers a location for them.
     */
    private function matchesLocationOffice(AlertRule $rule, Location $location, IngestedProduct $product): bool
    {
        $catalog = $product->product;

        if ($rule->category !== AlertCategory::WeatherAlerts
            || $catalog === null
            || $catalog->has_ugc
            || $catalog->has_polygon
            || $product->ugc_codes !== null
            || $product->cccc === null
            || ! $location->hasFix()) {
            return false;
        }

        $office = $this->officeCovering($this->ensureZonesResolved($location));

        return $office !== null && $this->matchesWfo($office, $product->cccc);
    }

    /**
     * The NWS office (CWA) that covers a location, read from its cached forecast zone, else its county.
     * The zone and county codes are real UGC codes ("COZ040", "COC014"); the tables store the state
     * and the zone number or FIPS separately.
     */
    private function officeCovering(Location $location): ?string
    {
        $zone = $location->forecast_zone_ugc;

        if (is_string($zone) && strlen($zone) === 6) {
            $office = DB::table('public_zones')->where('state_zone', substr($zone, 0, 2).substr($zone, 3))->value('cwa');

            if ($office !== null) {
                return $office;
            }
        }

        $county = $location->county_ugc;

        if (is_string($county) && strlen($county) === 6) {
            return DB::table('counties')
                ->whereRaw('upper(state) = ?', [substr($county, 0, 2)])
                ->whereRaw('right(fips, 3) = ?', [substr($county, 3)])
                ->value('cwa');
        }

        return null;
    }

    /**
     * @param  list<string>  $codes  the county/zone codes the product's settings name
     */
    private function matchesCountyZone(array $codes, IngestedProduct $product): bool
    {
        if ($codes === [] || $product->ugc_codes === null) {
            return false;
        }

        return array_intersect($codes, $product->ugc_codes) !== [];
    }

    private function ensureZonesResolved(Location $location): Location
    {
        if ($location->forecast_zone_ugc !== null || $location->county_ugc !== null) {
            return $location;
        }

        app(ResolveLocationZones::class)($location);

        return $location->refresh();
    }

    private function matchesWfo(?string $wfo, ?string $cccc): bool
    {
        if ($wfo === null || $cccc === null) {
            return false;
        }

        return strtoupper($wfo) === strtoupper(substr($cccc, 1));
    }

    /**
     * Nationwide/Wfo/CountyZone matches, and a
     * Location/Polygon/RiskArea match with zero locations or none
     * that matched, pass $matchedLocation = null -- "quiet hours" implicitly
     * means "my nighttime", which has no well-defined answer without a
     * specific location. These always deliver regardless of any
     * quiet_hours_start/end value on the row.
     *
     * A matched Location with no timezone of its own uses the owner's
     * profile timezone; if neither resolves, the rule is not suppressed.
     *
     * Uses the location that actually matched THIS delivery, not an
     * arbitrary or aggregated location across every location the rule's
     * products have -- see the design spec for why that's the only well-defined
     * choice once a rule can have more than one.
     */
    private function isWithinQuietHours(AlertRule $rule, ?Location $matchedLocation): bool
    {
        if ($rule->quiet_hours_start === null || $rule->quiet_hours_end === null) {
            return false;
        }

        $timezone = $matchedLocation === null ? null : Timezones::forLocation($matchedLocation);
        if ($timezone === null) {
            return false;
        }

        $now = Carbon::now($timezone);
        $start = $now->copy()->setTimeFromTimeString($rule->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($rule->quiet_hours_end);

        if ($start->lessThanOrEqualTo($end)) {
            return $now->between($start, $end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }
}

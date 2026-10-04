<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Support\Geography\GeographyMatch;
use App\Support\Timezones;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Matches a newly ingested NWWS product against active AlertRules and
 * dispatches DeliverWeatherAlert per matched rule x notification channel.
 * Mirrors App\Actions\Visibility\MatchActivityAlertRules's shape.
 *
 * AlertCategory::SpcWpc shares WeatherAlerts' product-based matching and
 * geography rules (see matchesGeography()); it additionally filters on
 * risk_levels (see matchesRiskLevel()), which WeatherAlerts doesn't have.
 */
class MatchWeatherAlertRules
{
    private const PRODUCT_MATCHED_CATEGORIES = [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc];

    public function __invoke(IngestedProduct $product): void
    {
        if ($product->product_id === null) {
            return;
        }

        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', self::PRODUCT_MATCHED_CATEGORIES)
                ->whereHas('products', fn (Builder $q) => $q->whereKey($product->product_id))
                ->when($product->is_test, fn (Builder $query) => $query->whereHas('user', fn (Builder $q) => $q->where('is_test_account', true)))
                ->get();
        } catch (Throwable $e) {
            logger()->error('Failed to query matching AlertRules', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (AlertRule $rule) use ($product): void {
            $match = $this->matchesGeographySafely($rule, $product);

            if ($match->matched && $this->matchesRiskLevel($rule, $product)) {
                $this->dispatchOrSuppress($rule, $product, $match->location);
            }
        });
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

    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product, ?Location $matchedLocation): void
    {
        $suppressed = $this->isWithinQuietHours($rule, $matchedLocation);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed, $matchedLocation): void {
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
                    DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'category' => $rule->category->value,
                        'subject' => app(RenderAlertSubject::class)($rule, $product->product, $matchedLocation),
                        'ingested_product_id' => $product->id,
                        'dispatched_at' => now(),
                    ]);

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, $matchedLocation?->id);
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

    private function matchesGeography(AlertRule $rule, IngestedProduct $product): GeographyMatch
    {
        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => GeographyMatch::matched(),
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::CountyZone => $this->matchesCountyZone($rule, $product) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::Location => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesLocationZones($location, $product)),
            MatchingMode::Polygon => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesPolygon($location, $product) ?? $this->matchesLocationZones($location, $product)),
            MatchingMode::RiskArea => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesRiskArea($rule, $location, $product) ?? $this->matchesLocationZones($location, $product)),
            null => GeographyMatch::notMatched(),
        };
    }

    /**
     * "Any attached location matches -> the rule fires": the first location,
     * ordered by location id (AlertRule::locations()'s explicit orderBy),
     * not attachment order, whose check succeeds wins and is the one
     * downstream code (quiet hours, {location} subject rendering) treats as
     * "the" match for this delivery. A rule with zero attached locations
     * never matches in these three modes -- there is nothing to check.
     */
    private function matchesAnyLocation(AlertRule $rule, Closure $matchesLocation): GeographyMatch
    {
        foreach ($rule->locations as $location) {
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
     * Only the levels in the product's own `risk_level_options` count: a rule watching both the
     * Convective Outlook and the Fire Weather Outlook holds both categories' ticks in one list, and
     * a level ticked for one category must not filter the other's products. `matchesRiskArea()`
     * already ranks only levels found in the product's options, so it needs no equivalent change.
     */
    private function matchesRiskLevel(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category !== AlertCategory::SpcWpc || $rule->risk_levels === null || $rule->risk_levels === []) {
            return true;
        }

        // The rule's levels are one flat list across every category it watches (the form
        // keeps a checklist per category), so only the levels this product itself offers
        // count for it. None of them belonging to it means the subscriber ticked nothing
        // for this product's category: no filter, i.e. every level.
        $relevant = array_values(array_intersect($rule->risk_levels, $product->product?->risk_level_options ?? []));

        if ($relevant === []) {
            return true;
        }

        if ($product->risk_levels === null) {
            return true;
        }

        return array_intersect($relevant, $product->risk_levels) !== [];
    }

    /**
     * Takes the specific `Location` being checked (one of the rule's
     * possibly several attached locations), not the rule itself. Returns
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

        $activeWwaResult = $this->matchesActiveWwaPolygon($product, $lng, $lat);
        if ($activeWwaResult !== null) {
            return $activeWwaResult;
        }

        if ($product->polygon === null) {
            return null;
        }

        $wkt = $this->polygonToWkt($product->polygon);

        $result = DB::selectOne(
            'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
            [$wkt, $lng, $lat],
        );

        return $this->resultIsTrue($result);
    }

    /**
     * A VTEC-class product's persisted active_wwas polygon takes priority
     * over the triggering row's own (possibly null on a continuation
     * statement like SVS/FFS) polygon -- see SyncActiveWwa, which is what
     * keeps that polygon current across an event's whole lifetime. Returns
     * null (not false) both when the product isn't VTEC-class at all and
     * when no active_wwas row with a polygon exists yet, so the caller falls
     * back to the row's own polygon field either way.
     */
    private function matchesActiveWwaPolygon(IngestedProduct $product, float $lng, float $lat): ?bool
    {
        if ($product->vtec_office === null) {
            return null;
        }

        $activeWwa = ActiveWwa::query()
            ->forProduct($product)
            ->whereNotNull('polygon')
            ->first();

        if ($activeWwa === null) {
            return null;
        }

        $result = DB::selectOne(
            'SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM active_wwas WHERE id = ?',
            [$lng, $lat, $activeWwa->id],
        );

        return $this->resultIsTrue($result);
    }

    /**
     * "At or above" a threshold, not "exactly the checked level(s)": the
     * threshold is the LOWEST-ranked level among the rule's own risk_levels
     * selection (rank = position in Product::risk_level_options, already
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
     * Takes the specific location being checked (one of the rule's possibly
     * several attached locations) rather than reading $rule->location.
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
        $selectedRanks = collect($rule->risk_levels ?? [])
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

    private function matchesCountyZone(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->county_zone_codes === null || $product->ugc_codes === null) {
            return false;
        }

        $ruleCodes = explode(',', $rule->county_zone_codes);

        return array_intersect($ruleCodes, $product->ugc_codes) !== [];
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
     * Nationwide/Wfo/CountyZone rules, and a
     * Location/Polygon/RiskArea rule with zero attached locations or none
     * that matched, pass $matchedLocation = null -- "quiet hours" implicitly
     * means "my nighttime", which has no well-defined answer without a
     * specific location. These always deliver regardless of any
     * quiet_hours_start/end value on the row.
     *
     * A matched Location with no timezone of its own uses the owner's
     * profile timezone; if neither resolves, the rule is not suppressed.
     *
     * Uses the location that actually matched THIS delivery, not an
     * arbitrary or aggregated location across every location the rule has
     * attached -- see the design spec for why that's the only well-defined
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

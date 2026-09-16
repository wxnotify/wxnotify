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
        $categories = [AlertCategory::ForecastDiscussion];
        if ($product->product_id !== null) {
            array_push($categories, ...self::PRODUCT_MATCHED_CATEGORIES);
        }

        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', $categories)
                ->where(function (Builder $query) use ($product) {
                    $query->where('category', AlertCategory::ForecastDiscussion)
                        ->orWhere(fn (Builder $q) => $q->whereIn('category', self::PRODUCT_MATCHED_CATEGORIES)
                            ->whereHas('products', fn (Builder $q) => $q->whereKey($product->product_id)));
                })
                ->get();
        } catch (Throwable $e) {
            logger()->error('Failed to query matching AlertRules', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (AlertRule $rule) use ($product): void {
            if ($this->matchesGeographySafely($rule, $product) && $this->matchesRiskLevel($rule, $product)) {
                $this->dispatchOrSuppress($rule, $product);
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
    private function matchesGeographySafely(AlertRule $rule, IngestedProduct $product): bool
    {
        try {
            return DB::transaction(fn (): bool => $this->matchesGeography($rule, $product));
        } catch (Throwable $e) {
            logger()->error('Failed to evaluate geography match for AlertRule', [
                'rule_id' => $rule->id,
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product): void
    {
        $suppressed = $this->isWithinQuietHours($rule);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed): void {
            try {
                if ($suppressed) {
                    DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'dispatched_at' => now(),
                    ]);

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
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

    private function matchesGeography(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category === AlertCategory::ForecastDiscussion) {
            return $this->matchesWfo($rule->wfo, $product->cccc);
        }

        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => true,
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc),
            MatchingMode::Location => $this->matchesLocationZones($rule, $product),
            MatchingMode::CountyZone => $this->matchesCountyZone($rule, $product),
            MatchingMode::Polygon => $this->matchesPolygon($rule, $product) ?? $this->matchesLocationZones($rule, $product),
            null => false,
        };
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
     */
    private function matchesRiskLevel(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category !== AlertCategory::SpcWpc || $rule->risk_levels === null || $rule->risk_levels === []) {
            return true;
        }

        if ($product->risk_levels === null) {
            return true;
        }

        return array_intersect($rule->risk_levels, $product->risk_levels) !== [];
    }

    /**
     * Returns null (not false) when the product has no polygon, so the
     * caller can fall back to matchesLocationZones() — most VTEC products
     * don't carry a polygon, and a subscriber in Polygon mode shouldn't be
     * silently missed on a warning type that's UGC-only.
     */
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($rule->location === null || ! $rule->location->hasFix()) {
            return null;
        }

        $lng = (float) $rule->location->lng;
        $lat = (float) $rule->location->lat;

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

    private function matchesLocationZones(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->location === null || ! $rule->location->hasFix() || $product->ugc_codes === null) {
            return false;
        }

        $location = $this->ensureZonesResolved($rule->location);
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
     * Nationwide/Wfo/ForecastDiscussion rules have no Location at all —
     * "quiet hours" implicitly means "my nighttime", which has no
     * well-defined answer without one. These always deliver regardless of
     * any quiet_hours_start/end value on the row.
     */
    private function isWithinQuietHours(AlertRule $rule): bool
    {
        if ($rule->quiet_hours_start === null || $rule->quiet_hours_end === null) {
            return false;
        }

        if (! in_array($rule->matching_mode, [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::CountyZone], true)) {
            return false;
        }

        $timezone = $rule->location?->timezone;
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

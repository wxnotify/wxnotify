<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;

/**
 * Matches a batch of decoded GLM flashes (see pipeline/glm-decoder) against
 * active Lightning Alert rules via PostGIS ST_DWithin, cast to geography so
 * the radius is a real distance in meters rather than degrees of the
 * underlying SRID — the same point-radius shape Mode 02 location matching
 * already uses elsewhere, just with a per-rule radius instead of a fixed
 * one.
 *
 * A fixed buffer (the sensor's worst-case 14km/~8.7mi resolution) is added
 * on top of the subscriber's configured radius, biased toward
 * over-inclusion rather than under-inclusion — the same principle already
 * applied to Heads Up: a flash's true position could plausibly be anywhere
 * within that padding, so a match just outside the nominal radius is more
 * likely a real miss than a false one.
 *
 * A single active storm can produce flashes continuously for hours; without
 * a cooldown this would re-fire the same rule every poll cycle for as long
 * as it kept producing flashes. lightning_last_alerted_at suppresses
 * re-firing within COOLDOWN_MINUTES of a rule's last alert.
 */
class MatchLightningFlashes
{
    private const SENSOR_BUFFER_MILES = 8.7;

    private const COOLDOWN_MINUTES = 30;

    private const METERS_PER_MILE = 1609.34;

    /**
     * @param  array<int, array{id: string, lat: float, lon: float}>  $flashes
     */
    public function __invoke(array $flashes): void
    {
        foreach ($flashes as $flash) {
            $this->matchFlash((float) $flash['lat'], (float) $flash['lon']);
        }
    }

    private function matchFlash(float $lat, float $lon): void
    {
        $rules = AlertRule::query()
            ->join('locations', 'locations.id', '=', 'alert_rules.location_id')
            ->where('alert_rules.category', AlertCategory::Lightning)
            ->where('alert_rules.is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('alert_rules.lightning_last_alerted_at')
                ->orWhere('alert_rules.lightning_last_alerted_at', '<=', now()->subMinutes(self::COOLDOWN_MINUTES)))
            ->whereRaw(
                // radius_miles is an integer column: adding a bare `?`
                // placeholder makes Postgres infer that parameter as
                // integer too, and choke on a fractional buffer value —
                // ::numeric casts on the parameters sidestep that entirely.
                'ST_DWithin('.
                    'ST_SetSRID(ST_MakePoint(locations.lng, locations.lat), 4269)::geography, '.
                    'ST_SetSRID(ST_MakePoint(?, ?), 4269)::geography, '.
                    '(alert_rules.radius_miles + ?::numeric) * ?::numeric'.
                ')',
                [$lon, $lat, self::SENSOR_BUFFER_MILES, self::METERS_PER_MILE],
            )
            ->select('alert_rules.*')
            ->with(['location', 'notificationChannels'])
            ->get();

        foreach ($rules as $rule) {
            $this->alert($rule);
        }
    }

    private function alert(AlertRule $rule): void
    {
        $rule->forceFill(['lightning_last_alerted_at' => now()])->save();

        $message = "Lightning detected in your area near {$rule->location->label}.";

        foreach ($rule->notificationChannels as $channel) {
            DeliverScheduledAlert::dispatch($rule->id, $channel->id, $rule->displayName(), $message);
        }
    }
}

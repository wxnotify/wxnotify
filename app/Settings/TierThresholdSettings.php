<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class TierThresholdSettings extends Settings
{
    public int $free_locations;

    public int $plus_locations;

    public int $free_phone_locations;

    public int $plus_phone_locations;

    public int $free_alerts;

    public int $plus_alerts;

    public int $pro_alerts;

    public int $free_no_cost_channels;

    public int $free_traccar_servers;

    public int $plus_traccar_servers;

    public int $pro_traccar_servers;

    public int $free_traccar_devices_per_server;

    public int $plus_traccar_devices_per_server;

    public int $pro_traccar_devices_per_server;

    public int $lightning_min_radius_miles;

    public static function group(): string
    {
        return 'tier_threshold';
    }
}

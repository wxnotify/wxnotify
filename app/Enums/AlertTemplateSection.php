<?php

namespace App\Enums;

/**
 * The 7 sections a weather_alerts-category AlertTemplate can include,
 * declared in the fixed canonical order they always render in -- a
 * subscriber's template never controls order, only which of these are
 * present (see the design doc's rejected "reorderable" approach).
 */
enum AlertTemplateSection: string
{
    case Hazard = 'hazard';
    case Source = 'source';
    case Impact = 'impact';
    case LocationsImpacted = 'locations_impacted';
    case Expiration = 'expiration';
    case Motion = 'motion';
    case CallToAction = 'call_to_action';

    public function label(): string
    {
        return match ($this) {
            self::Hazard => 'Hazard',
            self::Source => 'Source',
            self::Impact => 'Impact',
            self::LocationsImpacted => 'Locations impacted',
            self::Expiration => 'Expiration',
            self::Motion => 'Motion',
            self::CallToAction => 'Call to action',
        };
    }
}

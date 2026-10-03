<?php

namespace App\Enums;

enum MatchingMode: string
{
    case Nationwide = 'nationwide';
    case Location = 'location';
    case Wfo = 'wfo';
    case CountyZone = 'county_zone';
    case Polygon = 'polygon';
    case RiskArea = 'risk_area';

    public function label(): string
    {
        return match ($this) {
            self::Nationwide => 'All products, any location',
            self::Location => 'By my location',
            self::Wfo => 'By issuing office (WFO)',
            self::CountyZone => 'By county/zone (UGC)',
            self::Polygon => 'By product polygon',
            self::RiskArea => 'By risk area at my location',
        };
    }

    /**
     * A few words for a table cell; label() is a sentence for the form.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Nationwide => 'Nationwide',
            self::Location => 'By location',
            self::Wfo => 'Issuing office',
            self::CountyZone => 'County/zone',
            self::Polygon => 'By polygon',
            self::RiskArea => 'By risk area',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::Nationwide => 'Every issuance reaches you, no matter where it applies. Built for chasers tracking weather nationally.',
            self::Location => 'Fires when a saved location falls inside the product\'s affected area.',
            self::Wfo => 'Follow everything a specific NWS office issues, independent of your own location.',
            self::CountyZone => 'Classic zone-based matching against NWS county/forecast-zone (UGC) codes.',
            self::Polygon => 'Point-in-polygon matching against the warning\'s actual geometry, for the highest precision available.',
            self::RiskArea => 'Fires when your saved location falls inside the area for the risk level(s) you selected above, or anything more severe. Falls back to point-in-polygon matching for products with no categorical risk levels.',
        };
    }
}

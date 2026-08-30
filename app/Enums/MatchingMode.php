<?php

namespace App\Enums;

enum MatchingMode: string
{
    case Nationwide = 'nationwide';
    case Location = 'location';
    case Wfo = 'wfo';
    case CountyZone = 'county_zone';
    case Polygon = 'polygon';

    public function label(): string
    {
        return match ($this) {
            self::Nationwide => 'All products, any location',
            self::Location => 'By my location',
            self::Wfo => 'By issuing office (WFO)',
            self::CountyZone => 'By county/zone (UGC)',
            self::Polygon => 'By product polygon',
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
        };
    }
}

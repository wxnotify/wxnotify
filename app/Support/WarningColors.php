<?php

namespace App\Support;

/**
 * The colour a warning or watch is drawn in on the map: the NWS convention for the common events,
 * else one per family.
 */
class WarningColors
{
    private const BY_EVENT = [
        'Tornado Warning' => '#ff0000',
        'Severe Thunderstorm Warning' => '#ffa500',
        'Flash Flood Warning' => '#8b0000',
        'Flood Warning' => '#00ff00',
        'Special Marine Warning' => '#ffa500',
        'Extreme Wind Warning' => '#ff8c00',
        'Hurricane Warning' => '#dc143c',
        'Tropical Storm Warning' => '#b22222',
        'Winter Storm Warning' => '#ff69b4',
        'Tornado Watch' => '#ffff00',
        'Severe Thunderstorm Watch' => '#db7093',
    ];

    private const BY_FAMILY = ['warning' => '#d32f2f', 'watch' => '#f9a825'];

    private const OTHER = '#6b7280';

    public static function for(string $event, ?string $family): string
    {
        return self::BY_EVENT[$event] ?? self::BY_FAMILY[$family ?? ''] ?? self::OTHER;
    }
}

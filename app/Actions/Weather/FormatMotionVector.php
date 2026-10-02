<?php

namespace App\Actions\Weather;

use App\Enums\UnitSystem;
use App\Models\ActiveWwa;
use App\Support\Units;

/**
 * Formats an ActiveWwa's motion_direction_degrees/motion_speed_kt into a
 * readable line like "Moving ESE at 20 mph" (or "... at 32 km/h"), for the Motion section of a
 * composed alert template body. No existing compass-direction utility
 * exists elsewhere in this codebase to reuse -- this is new, self-contained
 * logic. Knots are converted to mph (x1.15078) and shown to the nearest
 * whole number in the subscriber's own unit (mph or km/h), even though
 * NWS's own eventMotionDescription parameter (see
 * SyncActiveWwa::parseMotion()) reports in knots.
 */
class FormatMotionVector
{
    private const COMPASS_POINTS = [
        'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW',
    ];

    public function __invoke(?ActiveWwa $activeWwa, UnitSystem $units = UnitSystem::Imperial): ?string
    {
        if ($activeWwa === null || $activeWwa->motion_direction_degrees === null || $activeWwa->motion_speed_kt === null) {
            return null;
        }

        $normalizedDegrees = ((int) round($activeWwa->motion_direction_degrees) % 360 + 360) % 360;
        $compass = self::COMPASS_POINTS[(int) round($normalizedDegrees / 22.5) % 16];
        $mph = $activeWwa->motion_speed_kt * 1.15078;

        return "Moving {$compass} at ".(new Units($units))->speed($mph);
    }
}

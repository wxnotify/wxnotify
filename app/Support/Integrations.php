<?php

namespace App\Support;

use App\Enums\Integration;
use App\Settings\IntegrationSettings;

/**
 * Runtime switches and non-secret endpoints for third-party integrations.
 * Credentials stay in .env. Backends call this rather than reading the
 * settings class directly; most integrations have no backend yet.
 */
class Integrations
{
    public static function enabled(Integration $integration): bool
    {
        return (bool) app(IntegrationSettings::class)->{$integration->enabledProperty()};
    }

    public static function endpoint(Integration $integration): ?string
    {
        $property = $integration->endpointProperty();

        if ($property === null) {
            return null;
        }

        $value = app(IntegrationSettings::class)->{$property};

        return filled($value) ? $value : null;
    }
}

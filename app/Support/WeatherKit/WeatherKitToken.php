<?php

namespace App\Support\WeatherKit;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Signs the short-lived ES256 bearer token Apple's WeatherKit REST API requires.
 * Never throws and never logs key material: an unusable key simply yields no token.
 */
final class WeatherKitToken
{
    private const LIFETIME_SECONDS = 3000;

    private const CACHE_SECONDS = 2700;

    public function isConfigured(): bool
    {
        return $this->value('team_id') !== null
            && $this->value('service_id') !== null
            && $this->value('key_id') !== null
            && $this->privateKey() !== null;
    }

    public function token(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $keyId = (string) $this->value('key_id');

        try {
            $cached = Cache::get('weatherkit:token:'.$keyId);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $teamId = (string) $this->value('team_id');
            $serviceId = (string) $this->value('service_id');
            $now = time();

            $token = JWT::encode([
                'iss' => $teamId,
                'sub' => $serviceId,
                'iat' => $now,
                'exp' => $now + self::LIFETIME_SECONDS,
            ], (string) $this->privateKey(), 'ES256', $keyId, ['id' => $teamId.'.'.$serviceId]);

            Cache::put('weatherkit:token:'.$keyId, $token, self::CACHE_SECONDS);

            return $token;
        } catch (Throwable) {
            return null;
        }
    }

    private function value(string $key): ?string
    {
        $value = config('services.weatherkit.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function privateKey(): ?string
    {
        $inline = $this->value('private_key');

        if ($inline !== null) {
            return str_replace('\n', "\n", $inline);
        }

        $path = $this->value('private_key_path');

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }
}

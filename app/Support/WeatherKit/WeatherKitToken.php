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
        $teamId = (string) $this->value('team_id');
        $serviceId = (string) $this->value('service_id');
        $cacheKey = 'weatherkit:token:'.hash('sha256', $teamId."\0".$serviceId."\0".$keyId);

        try {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $now = time();

            $token = JWT::encode([
                'iss' => $teamId,
                'sub' => $serviceId,
                'iat' => $now,
                'exp' => $now + self::LIFETIME_SECONDS,
            ], (string) $this->privateKey(), 'ES256', $keyId, ['id' => $teamId.'.'.$serviceId]);

            Cache::put($cacheKey, $token, self::CACHE_SECONDS);

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

        $path = $this->keyFilePath($this->value('private_key_path'));

        if ($path === null) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    /**
     * A relative key path is looked up under storage/ and then the project root, so
     * `app/apple/AuthKey_XXXX.p8` means storage/app/apple/AuthKey_XXXX.p8.
     */
    private function keyFilePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $candidates = str_starts_with($path, '/') ? [$path] : [$path, storage_path($path), base_path($path)];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

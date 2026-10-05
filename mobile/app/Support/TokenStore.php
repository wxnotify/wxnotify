<?php

namespace App\Support;

use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;
use RuntimeException;

/**
 * Everything secret the app keeps: the API token, the location beacon's token and the in-flight sign-in (PKCE verifier and
 * state, held in secure storage so a sign-in survives the OS suspending the app while the
 * system browser is open). Both are readable after first unlock, because background work
 * (location uploads, planned) runs while the phone is locked. Nothing here is written to
 * SQLite, the session or a log.
 */
class TokenStore
{
    private const TOKEN = 'wxnotify_api_token';

    private const PENDING = 'wxnotify_pending_signin';

    private const BEACON = 'wxnotify_location_beacon_token';

    private const BEACON_URL = 'wxnotify_location_beacon_url';

    public function token(): ?string
    {
        return SecureStorage::get(self::TOKEN) ?: null;
    }

    public function put(string $token): void
    {
        $this->write(self::TOKEN, $token);
    }

    public function forget(): void
    {
        SecureStorage::delete(self::TOKEN);
    }

    public function begin(string $verifier, string $state): void
    {
        $this->write(self::PENDING, json_encode(['verifier' => $verifier, 'state' => $state]));
    }

    /**
     * @return array{verifier: string, state: string}|null
     */
    public function pending(): ?array
    {
        $data = json_decode((string) SecureStorage::get(self::PENDING), true);

        return is_array($data) && is_string($data['verifier'] ?? null) && is_string($data['state'] ?? null)
            ? ['verifier' => $data['verifier'], 'state' => $data['state']]
            : null;
    }

    public function clearPending(): void
    {
        SecureStorage::delete(self::PENDING);
    }

    public function beaconToken(): ?string
    {
        return SecureStorage::get(self::BEACON) ?: null;
    }

    public function beaconUrl(): ?string
    {
        return SecureStorage::get(self::BEACON_URL) ?: null;
    }

    /**
     * The narrow token (and the endpoint the server gave with it) that only the native location
     * beacon uses. It is never the app token, which stays out of the plugin's plain preferences.
     */
    public function putBeaconToken(string $token, string $url): void
    {
        $this->write(self::BEACON, $token);
        $this->write(self::BEACON_URL, $url);
    }

    public function forgetBeaconToken(): void
    {
        SecureStorage::delete(self::BEACON);
        SecureStorage::delete(self::BEACON_URL);
    }

    private function write(string $key, string $value): void
    {
        if (! SecureStorage::set($key, $value, SecureStorageAccessibility::AfterFirstUnlock)) {
            throw new RuntimeException('Could not store the sign-in securely on this device.');
        }
    }
}

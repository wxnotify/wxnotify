<?php

namespace App\Support;

use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;
use RuntimeException;

/**
 * Everything secret the app keeps: the API token and the in-flight sign-in (PKCE verifier and
 * state, held in secure storage so a sign-in survives the OS suspending the app while the
 * system browser is open). Both are readable after first unlock, because background work
 * (location uploads, planned) runs while the phone is locked. Nothing here is written to
 * SQLite, the session or a log.
 */
class TokenStore
{
    private const TOKEN = 'wxnotify_api_token';

    private const PENDING = 'wxnotify_pending_signin';

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

    private function write(string $key, string $value): void
    {
        if (! SecureStorage::set($key, $value, SecureStorageAccessibility::AfterFirstUnlock)) {
            throw new RuntimeException('Could not store the sign-in securely on this device.');
        }
    }
}

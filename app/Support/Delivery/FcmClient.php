<?php

namespace App\Support\Delivery;

use App\Enums\Secret;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends one push message through the Firebase Cloud Messaging v1 HTTP API. Authentication is a
 * service-account JWT (RS256, signed with openssl) traded at Google's token endpoint for an
 * access token that is cached until shortly before it expires. Both hosts are fixed (no
 * subscriber-controlled URL), redirects are off, and there is exactly one attempt: the outcome
 * is the delivery-log row, not a retried job.
 */
class FcmClient
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** A weather alert that could not be delivered within an hour is stale: FCM drops it (default 4 weeks). */
    private const TTL_SECONDS = 3600;

    /**
     * The high-importance channel the nativephp firebase plugin creates in its service. Without it
     * Android shows a background push in the SDK's fallback "Miscellaneous" channel (default
     * importance, no heads-up banner), which is wrong for a weather alert.
     */
    private const ANDROID_CHANNEL = 'fcm_default_channel';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, string>  $data  string values only (an FCM requirement)
     * @param  int|null  $badge  the iOS app-icon badge number; null leaves the badge alone
     */
    public function send(string $deviceToken, string $title, string $body, array $data = [], ?int $badge = null): FcmResult
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return FcmResult::failed('Push is not configured on this server.');
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            return FcmResult::failed('Could not get a Firebase access token.');
        }

        try {
            $response = $this->request()->withToken($accessToken)->post(
                'https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send',
                ['message' => [
                    'token' => $deviceToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => (object) $data,
                    'android' => [
                        'priority' => 'HIGH',
                        'ttl' => self::TTL_SECONDS.'s',
                        'notification' => ['channel_id' => self::ANDROID_CHANNEL],
                    ],
                    'apns' => [
                        'headers' => ['apns-expiration' => (string) (now()->timestamp + self::TTL_SECONDS)],
                        'payload' => ['aps' => ['sound' => 'default'] + ($badge !== null ? ['badge' => $badge] : [])],
                    ],
                ]],
            );
        } catch (ConnectionException) {
            return FcmResult::failed('Firebase could not be reached.');
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($credentials));

            return FcmResult::failed('Firebase rejected the server credentials (HTTP 401).');
        }

        return $this->classify($response);
    }

    private function classify(Response $response): FcmResult
    {
        if ($response->successful()) {
            return FcmResult::delivered();
        }

        if ($this->tokenIsInvalid($response)) {
            return FcmResult::unregistered('This device is no longer registered for push.');
        }

        return FcmResult::failed("Firebase rejected the message (HTTP {$response->status()}).");
    }

    /**
     * Reads the error body only to classify it; nothing from it is ever echoed.
     * SENDER_ID_MISMATCH (HTTP 403) means the token belongs to another Firebase project, so
     * it can never work here; PERMISSION_DENIED and THIRD_PARTY_AUTH_ERROR stay plain failures.
     */
    private function tokenIsInvalid(Response $response): bool
    {
        if (! in_array($response->status(), [400, 403, 404], true)) {
            return false;
        }

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && in_array($detail['errorCode'] ?? null, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true)) {
                return true;
            }
        }

        return $response->json('error.status') === 'INVALID_ARGUMENT'
            && str_contains(strtolower((string) $response->json('error.message')), 'registration token');
    }

    /**
     * Whether a usable service account is available, from App settings or the key file.
     */
    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * The service account stored (encrypted) in App settings wins, so one entry reaches every server through
     * the shared database; the key file named by `FIREBASE_SERVICE_ACCOUNT` is the fallback, and is also used
     * when the stored value is not a usable service account.
     *
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        if (Secrets::isStored(Secret::FirebaseServiceAccount)) {
            $stored = $this->fromJson((string) Secrets::get(Secret::FirebaseServiceAccount));

            if ($stored !== null) {
                return $stored;
            }
        }

        $path = config('services.firebase.service_account');

        if (blank($path) || ! is_file($path)) {
            return null;
        }

        return $this->fromJson((string) file_get_contents($path));
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function fromJson(string $contents): ?array
    {
        $json = json_decode($contents, true);

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! is_array($json) || ! is_string($json[$key] ?? null) || $json[$key] === '') {
                return null;
            }
        }

        return ['project_id' => $json['project_id'], 'client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $cached = Cache::get($this->cacheKey($credentials));

        if (is_string($cached)) {
            return $cached;
        }

        $jwt = $this->signedJwt($credentials);

        if ($jwt === null) {
            return null;
        }

        try {
            $response = $this->request()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put($this->cacheKey($credentials), $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 300)));

        return $token;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function signedJwt(array $credentials): ?string
    {
        $now = time();
        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            return null;
        }

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return implode('.', [...$segments, self::base64Url($signature)]);
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function cacheKey(array $credentials): string
    {
        return 'fcm.access_token.'.sha1($credentials['client_email']);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false]);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

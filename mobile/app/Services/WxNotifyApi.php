<?php

namespace App\Services;

use App\Exceptions\Api\ChannelLimitReached;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\InvalidCode;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\RateLimited;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\TestFailed;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Support\TokenStore;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Network;

/**
 * The app's only way to reach WxNotify. Failures become typed exceptions the screens handle:
 * Unauthenticated (clear the token, go to Sign in), Offline, Retryable (5xx; RateLimited, a Retryable, for 429), Rejected.
 */
class WxNotifyApi
{
    /** How long a request may take before it counts as offline. */
    public const TIMEOUT_SECONDS = 15;

    /** A push tap's read: a slow server only queues it for the next feed load. */
    public const TAP_READ_TIMEOUT_SECONDS = 5;

    public function __construct(private TokenStore $tokens) {}

    /**
     * @return array{token: string, expires_at: string, user: array<string, mixed>}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $body = $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/auth/token', ['code' => $code, 'code_verifier' => $verifier]),
            authenticated: false,
        );

        if (! is_string($body['token'] ?? null) || ! is_array($body['user'] ?? null)) {
            throw new Retryable;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/me')));
    }

    /**
     * A page of the feed of pushed alerts, each entry with its `read` flag. `meta.unread_count` is the
     * subscriber's number of unread alerts (all pages), or null when the server did not send a number.
     * `meta.as_of` is the server time the page was read at (the cutoff "Mark all as read" sends), or null
     * from a backend that does not report one.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string, unread_count: ?int, as_of: ?string}}
     */
    public function alerts(?string $before = null, int $limit = 25): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/alerts', array_filter(
            ['limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null,
        )));

        $this->data($body);

        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $body['meta'] = [...$meta, 'next_cursor' => $meta['next_cursor'] ?? null, 'unread_count' => is_int($meta['unread_count'] ?? null) ? $meta['unread_count'] : null, 'as_of' => is_string($meta['as_of'] ?? null) ? $meta['as_of'] : null];

        return $body;
    }

    /**
     * Marks one alert read for the subscriber (on every phone); idempotent.
     *
     * @return int the subscriber's unread count afterwards
     *
     * @throws Rejected when the alert is not in the subscriber's feed (404)
     */
    public function markAlertRead(int $id): int
    {
        return $this->unreadCount($this->send(fn (PendingRequest $request) => $request->post("/api/v1/alerts/{$id}/read")));
    }

    /**
     * Marks the alert a tapped push named by its reference read (on every phone); idempotent, and a reference that
     * matches nothing yet is a no-op. Kept short: it runs while a tapped push opens the app.
     *
     * @return int the subscriber's unread count afterwards
     *
     * @throws ValidationFailed when the server refuses the reference (422)
     */
    public function markAlertReadByRef(string $ref): int
    {
        return $this->unreadCount($this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/alerts/read-ref', ['ref' => $ref]),
            timeoutSeconds: self::TAP_READ_TIMEOUT_SECONDS,
        ));
    }

    /**
     * Marks every alert of the subscriber read; idempotent. With `$through` (a feed's `as_of`) the server reads
     * only alerts created at or before it; without it, every alert (the only form an older backend knows).
     *
     * @return int the subscriber's unread count afterwards
     */
    public function markAllAlertsRead(?string $through = null): int
    {
        return $this->unreadCount($this->send(fn (PendingRequest $request) => $request->post(
            '/api/v1/alerts/read-all',
            $through === null ? [] : ['through' => $through],
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(int $id): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get("/api/v1/alerts/{$id}")));
    }

    public function signOut(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/auth/token'));
    }

    /**
     * `tap_ref` tells the server this build opens tap links that carry an alert reference (/product/{link}/{ref},
     * /alert/{ref}); without it the server keeps sending the older links.
     */
    public function registerPushToken(string $token, string $platform): void
    {
        $this->send(fn (PendingRequest $request) => $request->put('/api/v1/push-token', ['token' => $token, 'platform' => $platform, 'tap_ref' => true]));
    }

    public function unregisterPushToken(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/push-token'));
    }

    /**
     * @return array{location_id: int, beacon_token: string, url: string, interval_seconds: int}
     *
     * @throws LocationLimitReached when the plan has no room for another location
     */
    public function enableLocationSharing(): array
    {
        $data = $this->data($this->send(fn (PendingRequest $request) => $request->put('/api/v1/location-sharing')));

        if (! is_string($data['beacon_token'] ?? null) || ! is_string($data['url'] ?? null) || ! is_int($data['interval_seconds'] ?? null)) {
            throw new Retryable;
        }

        return $data;
    }

    /**
     * @return array{linked: bool, location_id: ?int, interval_seconds: ?int}
     */
    public function locationSharing(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/location-sharing')));
    }

    public function disableLocationSharing(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/location-sharing'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function alertRules(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/alert-rules')));
    }

    /**
     * @return array<string, mixed>
     */
    public function alertRule(int $id): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get("/api/v1/alert-rules/{$id}")));
    }

    /**
     * Creates a rule (null id) or replaces one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationFailed
     * @throws Maintenance
     */
    public function saveAlertRule(?int $id, array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $id === null
            ? $request->post('/api/v1/alert-rules', $payload)
            : $request->put("/api/v1/alert-rules/{$id}", $payload)));
    }

    /**
     * @return array<string, mixed>
     */
    public function setAlertRuleActive(int $id, bool $active): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->patch("/api/v1/alert-rules/{$id}", ['is_active' => $active])));
    }

    public function deleteAlertRule(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/alert-rules/{$id}"));
    }

    /**
     * @param  int  $timeoutSeconds  shorter for a caller that must not wait (the welcome flow's slot lookup)
     * @return array<string, mixed>
     */
    public function alertRuleOptions(int $timeoutSeconds = self::TIMEOUT_SECONDS): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/alert-rule-options'), timeoutSeconds: $timeoutSeconds));
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public function nwsOffices(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/nws-offices')));
    }

    /**
     * A page of the product archive search. Filters that are null or empty are not sent.
     *
     * @param  array{q?: ?string, type?: ?int, wfo?: ?string, period?: ?string}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function products(array $filters, ?string $before = null, int $limit = 25): array
    {
        $query = array_filter(
            [...$filters, 'limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null && $value !== '',
        );

        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/products', $query));

        $this->data($body);

        return $body;
    }

    /**
     * The catalog products the archive search can filter by, grouped by category.
     *
     * @return array<int, array{category: string, label: string, products: array<int, array{id: int, name: string, code: string}>}>
     */
    public function productTypes(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/product-types')));
    }

    /**
     * @return array{data: array<int, array{code: string, label: string}>, next_after: ?string}
     */
    public function zones(?string $state, ?string $query, ?string $after = null): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/zones', array_filter(
            ['state' => $state, 'q' => $query, 'after' => $after],
            fn ($value) => $value !== null && $value !== '',
        )));

        return ['data' => $this->data($body), 'next_after' => $body['meta']['next_after'] ?? null];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function locations(): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/locations'));

        return ['data' => $this->data($body), 'meta' => is_array($body['meta'] ?? null) ? $body['meta'] : []];
    }

    /**
     * Creates a stationary location (null id) or edits one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws LocationLimitReached
     * @throws ValidationFailed
     */
    public function saveLocation(?int $id, array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $id === null
            ? $request->post('/api/v1/locations', $payload)
            : $request->put("/api/v1/locations/{$id}", $payload)));
    }

    /**
     * @throws Conflict when alerts still use the location or it follows a phone
     */
    public function deleteLocation(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/locations/{$id}"));
    }

    /**
     * The channel types the app can create, each with its setup fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channelTypes(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/channel-types')));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function channels(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/channels')));
    }

    /**
     * Creates a channel and has the server send its confirmation code.
     *
     * @param  array<string, mixed>  $payload  channel_type, optional label, and the type's fields
     * @return array<string, mixed>
     *
     * @throws ChannelLimitReached
     * @throws CodeNotSent
     * @throws ValidationFailed
     */
    public function createChannel(array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->post('/api/v1/channels', $payload)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCode
     */
    public function verifyChannel(int $id, string $code): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/verify", ['code' => $code])));
    }

    /**
     * @throws CodeNotSent
     */
    public function resendChannelCode(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/resend"));
    }

    /**
     * @throws TestFailed
     */
    public function testChannel(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->post("/api/v1/channels/{$id}/test"));
    }

    /**
     * @return array<string, mixed>
     */
    public function renameChannel(int $id, string $label): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->put("/api/v1/channels/{$id}", ['label' => $label])));
    }

    /**
     * @throws Conflict when the channel is some alert's only one
     */
    public function deleteChannel(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/channels/{$id}"));
    }

    /**
     * The map snapshot: the phone, the places, the warnings near them and the layer configuration.
     *
     * @return array<string, mixed>
     */
    public function map(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/map')));
    }

    /**
     * The warnings, watches and advisories in effect at the subscriber's places.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array{has_places: bool, generated_at: string}}
     */
    public function activeAlerts(): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/active-alerts'));

        $this->data($body);

        return $body;
    }

    /**
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    public function searchPlaces(string $query): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/places/search', ['q' => $query])));
    }

    public function reversePlace(float $lat, float $lng): ?string
    {
        $data = $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/places/reverse', ['lat' => $lat, 'lng' => $lng])));

        return is_string($data['address'] ?? null) ? $data['address'] : null;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call, bool $authenticated = true, int $timeoutSeconds = self::TIMEOUT_SECONDS): array
    {
        $this->assertOnline();

        $request = Http::baseUrl(config('wxnotify.api_url'))->acceptJson()->asJson()->timeout($timeoutSeconds);

        if ($authenticated) {
            $token = $this->tokens->token();

            if ($token === null) {
                throw new Unauthenticated;
            }

            $request = $request->withToken($token);
        }

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            throw new Offline;
        }

        return match (true) {
            $response->successful() => $this->decode($response),
            $response->status() === 401 => throw new Unauthenticated,
            $response->status() === 503 && str_starts_with((string) $response->header('Content-Type'), 'text/plain') => throw new Maintenance(trim($response->body()) ?: 'WxNotify is down for maintenance.'),
            $response->status() === 502 && $response->json('error') === 'code_not_sent' => throw new CodeNotSent,
            $response->status() === 502 && $response->json('error') === 'test_failed' => throw new TestFailed,
            $response->status() === 429 => throw new RateLimited,
            $response->serverError() => throw new Retryable,
            $response->status() === 422 && $response->json('error') === 'location_limit' => throw new LocationLimitReached,
            $response->status() === 422 && $response->json('error') === 'channel_limit' => throw new ChannelLimitReached((string) $response->json('message', '')),
            $response->status() === 422 && $response->json('error') === 'invalid_code' => throw new InvalidCode((string) $response->json('message', '')),
            $response->status() === 422 && is_array($response->json('errors')) => throw new ValidationFailed($this->firstMessages($response->json('errors')), (string) $response->json('message', '')),
            $response->status() === 409 => throw new Conflict((string) $response->json('error', ''), (string) $response->json('message', ''), array_values((array) $response->json('alerts', []))),
            default => throw new Rejected,
        };
    }

    /**
     * The first message of each field; a nested key such as `channel_ids.0` is reported against
     * its root field, and the first message seen for a field wins.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, string>
     */
    private function firstMessages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $key => $value) {
            $field = Str::before((string) $key, '.');
            $messages[$field] ??= (string) (is_array($value) ? ($value[0] ?? '') : $value);
        }

        return $messages;
    }

    /**
     * A success with no body (the sign-out 204) is empty; a body that is not JSON (a captive portal's
     * HTML page, a misconfigured host) is a transient failure, never a half-built array.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new Retryable;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        if (! is_array($body['data'] ?? null)) {
            throw new Retryable;
        }

        return $body['data'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function unreadCount(array $body): int
    {
        $count = $this->data($body)['unread_count'] ?? null;

        if (! is_int($count)) {
            throw new Retryable;
        }

        return $count;
    }

    private function assertOnline(): void
    {
        $status = Network::status();

        if ($status !== null && ($status->connected ?? true) === false) {
            throw new Offline;
        }
    }
}

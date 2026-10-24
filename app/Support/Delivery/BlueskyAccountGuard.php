<?php

namespace App\Support\Delivery;

use App\Exceptions\BlueskyAccountRefused;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifies that a Bluesky handle belongs to an account hosted on Bluesky's own servers, before
 * a Bluesky channel is saved and again before every send (owner decision: only Bluesky-hosted
 * accounts are supported).
 *
 * Why: Apprise's `bsky://` plugin resolves the handle itself on every send (Apprise 2.0.0,
 * spiked with `requests` monkeypatched): `public.api.bsky.app` resolveHandle, then the DID
 * document (`plc.directory/{did}` for did:plc, `https://{host}/.well-known/did.json` for
 * did:web), then it posts the app password to `com.atproto.server.createSession` on whatever
 * `serviceEndpoint` the document's first `AtprotoPersonalDataServer` service names. The document
 * is the account owner's to write, so without this check a subscriber could point WxNotify's
 * Apprise container at any host (SSRF) and have it carry the password there.
 *
 * The check, all on fixed hosts with the HTTP facade, connect 5 s / total 10 s, redirects off,
 * no credentials and no secret in any URL:
 * 1. resolveHandle on `public.api.bsky.app` gives the DID; it must match
 *    `^did:plc:[a-z2-7]{24}$` (did:web and every other method are refused; the DID is checked
 *    before it is put in a URL).
 * 2. `https://plc.directory/{did}` gives the document; its `id` must be the DID and its
 *    `alsoKnownAs` must hold `at://{handle}` (the handle is claimed in both directions).
 * 3. Every `AtprotoPersonalDataServer` service (Apprise takes the first, whatever its id) must
 *    be `#atproto_pds` and name exactly `https://{host}` (no trailing slash: Apprise appends
 *    `/xrpc/...` to the endpoint as written, and a real document never carries one) with
 *    the host exactly in ALLOWED_PDS_HOSTS or ending in one of ALLOWED_PDS_HOST_SUFFIXES under
 *    at least one more label. Real endpoints look like `https://puffball.us-east.host.bsky.network`
 *    (checked on plc.directory for bsky.app and jay.bsky.team; Apprise's source names
 *    `https://woodtuft.us-west.host.bsky.network`); `bsky.social` is the entryway.
 * A response over MAX_RESPONSE_BYTES, not JSON, or not shaped as expected, a non-200 or a
 * connection failure refuses with UNVERIFIED; a policy failure with NOT_HOSTED_BY_BLUESKY; a
 * handle Bluesky cannot resolve, a DID document plc.directory no longer has (404/410:
 * deactivated or tombstoned), or a document that does not claim the handle back, with
 * HANDLE_NOT_FOUND.
 *
 * Cache: a success is cached for CACHE_SECONDS under cacheKey() (a hash of the lower-cased
 * handle) ONLY for handles under `.bsky.social` (isCacheable()), whose resolution Bluesky
 * controls; a failure is never cached. A custom-domain handle (`alerts.example.com`) is looked
 * up again on every send, because its owner can re-point it at any moment (DNS TXT
 * `_atproto` or `/.well-known/atproto-did`) to another DID, including a did:web Apprise would
 * follow to any host.
 *
 * Save-time lookups (verifiedDidForSave(), the wizard) cost one of SAVE_LOOKUPS_PER_WINDOW
 * attempts per subscriber per SAVE_WINDOW_SECONDS (`bluesky-lookup:{user id}`, never the handle)
 * on a cache miss, refused with THROTTLED_MESSAGE: scripted submits would otherwise spend the
 * per-IP limit of public.api.bsky.app that every send and Apprise's own resolution share. Sends
 * are never throttled here.
 *
 * Residual risks, by design.
 * - TOCTOU: Apprise resolves the handle and the document itself after this check, so a send
 *   can still be redirected by an owner who changes their DID document, or re-points a
 *   custom-domain handle (to another did:plc or a did:web on any host), in the moment between
 *   our check and Apprise's; for a `.bsky.social` handle the window is up to the 10-minute
 *   cache. The only full fix is network-level egress control of the Apprise container
 *   (allow public.api.bsky.app, plc.directory, bsky.social, *.host.bsky.network and the other
 *   chat types' hosts), the owner/ops item already recorded for the chat types.
 * - Apprise persistent storage: `APPRISE_STATELESS_STORAGE` must stay unset/off on the Apprise
 *   container. With persistent storage, Apprise's get_identifier() returns a stored did and
 *   endpoint instead of resolving, and our per-send checks would no longer govern where it posts.
 * - Rate limits: Bluesky limits createSession per account per day and Apprise logs in on every
 *   send, so a very busy rule can hit that limit and fail sends until it resets (not mitigated).
 * - Latency: a send can take two lookups of up to 10 s each plus the Apprise call (no timeout of
 *   its own: Laravel's 30 s default), about 50 s worst case, close to the 60 s worker timeout.
 */
class BlueskyAccountGuard
{
    public const RESOLVE_HANDLE_URL = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle';

    public const PLC_DIRECTORY_URL = 'https://plc.directory/';

    /** A PDS host must be exactly one of these... */
    public const ALLOWED_PDS_HOSTS = ['bsky.social'];

    /** ...or end in one of these, after at least one more label. */
    public const ALLOWED_PDS_HOST_SUFFIXES = ['.host.bsky.network'];

    public const NOT_HOSTED_BY_BLUESKY = 'Only accounts hosted by Bluesky are supported. This account is hosted on another server, so WxNotify cannot post to it.';

    public const UNVERIFIED = 'We could not verify the Bluesky account right now. Try again later.';

    public const HANDLE_NOT_FOUND = 'We couldn\'t find a Bluesky account with that handle. Check the handle and try again.';

    public const CACHE_SECONDS = 600;

    /** The only handles whose successful check is cached. */
    public const CACHEABLE_HANDLE_SUFFIX = '.bsky.social';

    public const SAVE_LOOKUPS_PER_WINDOW = 10;

    public const SAVE_WINDOW_SECONDS = 600;

    /** `{minutes}` is filled in; never the policy or the unverified text. */
    public const THROTTLED_MESSAGE = 'Too many attempts. Try again in {minutes}.';

    /** DID documents and handle answers are a few hundred bytes. */
    public const MAX_RESPONSE_BYTES = 65536;

    private const DID_PATTERN = '/^did:plc:[a-z2-7]{24}$/D';

    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * The verified DID of the handle's account, for a send: from the cache for a `.bsky.social`
     * handle, otherwise looked up. Never throttled.
     *
     * @throws BlueskyAccountRefused
     */
    public function verifiedDid(string $handle): string
    {
        $handle = self::normalizeHandle($handle);

        return $this->cachedDid($handle) ?? $this->lookUpAndRemember($handle);
    }

    /**
     * The same check when a channel is saved: a cache miss costs one of the subscriber's
     * save-time attempts (a null id, no signed-in subscriber, shares one bucket).
     *
     * @throws BlueskyAccountRefused
     */
    public function verifiedDidForSave(string $handle, ?int $userId): string
    {
        $handle = self::normalizeHandle($handle);
        $cached = $this->cachedDid($handle);

        if ($cached !== null) {
            return $cached;
        }

        $key = 'bluesky-lookup:'.($userId ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, self::SAVE_LOOKUPS_PER_WINDOW)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new BlueskyAccountRefused(strtr(self::THROTTLED_MESSAGE, ['{minutes}' => $minutes === 1 ? '1 minute' : "{$minutes} minutes"]));
        }

        RateLimiter::hit($key, self::SAVE_WINDOW_SECONDS);

        return $this->lookUpAndRemember($handle);
    }

    /**
     * Whether a successful check of this handle may be cached: a handle under `.bsky.social`,
     * which Bluesky resolves. Expects a normalised handle (no leading `@`).
     */
    public static function isCacheable(string $handle): bool
    {
        $handle = self::normalizeHandle($handle);

        return str_ends_with($handle, self::CACHEABLE_HANDLE_SUFFIX) && strlen($handle) > strlen(self::CACHEABLE_HANDLE_SUFFIX);
    }

    private function cachedDid(string $handle): ?string
    {
        if (! self::isCacheable($handle)) {
            return null;
        }

        $cached = Cache::get(self::cacheKey($handle));

        return is_string($cached) && preg_match(self::DID_PATTERN, $cached) === 1 ? $cached : null;
    }

    /**
     * @throws BlueskyAccountRefused
     */
    private function lookUpAndRemember(string $handle): string
    {
        $did = $this->lookUp($handle);

        if (self::isCacheable($handle)) {
            Cache::put(self::cacheKey($handle), $did, self::CACHE_SECONDS);
        }

        return $did;
    }

    public static function cacheKey(string $handle): string
    {
        return 'bluesky-account:'.hash('sha256', self::normalizeHandle($handle));
    }

    /**
     * Whether a DID document's `serviceEndpoint` is exactly `https://{host}` on one of Bluesky's
     * own PDS hosts.
     */
    public static function isAllowedPdsEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || preg_match('#^https://([a-z0-9.-]+)$#D', $endpoint, $matches) !== 1) {
            return false;
        }

        $host = $matches[1];

        if (in_array($host, self::ALLOWED_PDS_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PDS_HOST_SUFFIXES as $suffix) {
            $pattern = '/^(?:'.self::LABEL.'\.)+'.preg_quote(ltrim($suffix, '.'), '/').'$/D';

            if (str_ends_with($host, $suffix) && preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Trimmed and lower case. Callers pass a handle already stripped of its leading `@`. */
    private static function normalizeHandle(string $handle): string
    {
        return strtolower(trim($handle));
    }

    /**
     * @throws BlueskyAccountRefused
     */
    private function lookUp(string $handle): string
    {
        $resolved = $this->fetchJson(self::RESOLVE_HANDLE_URL, ['handle' => $handle], notFoundStatuses: [400]);
        $did = $resolved['did'] ?? null;

        if (! is_string($did)) {
            throw new BlueskyAccountRefused(self::UNVERIFIED);
        }

        if (preg_match(self::DID_PATTERN, $did) !== 1) {
            throw new BlueskyAccountRefused(self::NOT_HOSTED_BY_BLUESKY);
        }

        $document = $this->fetchJson(self::PLC_DIRECTORY_URL.$did, notFoundStatuses: [404, 410]);

        if (($document['id'] ?? null) !== $did
            || ! is_array($document['alsoKnownAs'] ?? null)
            || ! in_array('at://'.$handle, array_map(fn (mixed $alias): mixed => is_string($alias) ? strtolower($alias) : null, $document['alsoKnownAs']), true)) {
            throw new BlueskyAccountRefused(self::HANDLE_NOT_FOUND);
        }

        if (! $this->hostedByBluesky($document['service'] ?? null, $did)) {
            throw new BlueskyAccountRefused(self::NOT_HOSTED_BY_BLUESKY);
        }

        return $did;
    }

    /**
     * At least one PDS service, and every one of them `#atproto_pds` on an allowed host.
     */
    private function hostedByBluesky(mixed $services, string $did): bool
    {
        if (! is_array($services)) {
            return false;
        }

        $pdsServices = array_filter($services, fn (mixed $service): bool => is_array($service)
            && ($service['type'] ?? null) === 'AtprotoPersonalDataServer');

        foreach ($pdsServices as $service) {
            if (! in_array($service['id'] ?? null, ['#atproto_pds', $did.'#atproto_pds'], true)
                || ! self::isAllowedPdsEndpoint($service['serviceEndpoint'] ?? null)) {
                return false;
            }
        }

        return $pdsServices !== [];
    }

    /**
     * A JSON object from a fixed host, or a refusal.
     *
     * @param  array<string, string>  $query
     * @param  list<int>  $notFoundStatuses  answers meaning the handle or account does not exist
     * @return array<string, mixed>
     *
     * @throws BlueskyAccountRefused
     */
    private function fetchJson(string $url, array $query = [], array $notFoundStatuses = []): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->acceptJson()
                ->get($url, $query);
        } catch (ConnectionException|TransferException) {
            throw new BlueskyAccountRefused(self::UNVERIFIED);
        }

        if (in_array($response->status(), $notFoundStatuses, true)) {
            throw new BlueskyAccountRefused(self::HANDLE_NOT_FOUND);
        }

        $decoded = $this->decode($response);

        if ($decoded === null) {
            throw new BlueskyAccountRefused(self::UNVERIFIED);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if ($response->status() !== 200) {
            return null;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        $decoded = json_decode($body, true, 32);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}

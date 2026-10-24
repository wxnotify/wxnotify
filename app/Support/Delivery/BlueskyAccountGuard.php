<?php

namespace App\Support\Delivery;

use App\Exceptions\BlueskyAccountRefused;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
 *    be `#atproto_pds` and name `https://{host}` (an optional trailing slash, nothing else) with
 *    the host exactly in ALLOWED_PDS_HOSTS or ending in one of ALLOWED_PDS_HOST_SUFFIXES under
 *    at least one more label. Real endpoints look like `https://puffball.us-east.host.bsky.network`
 *    (checked on plc.directory for bsky.app and jay.bsky.team; Apprise's source names
 *    `https://woodtuft.us-west.host.bsky.network`); `bsky.social` is the entryway.
 * A response over MAX_RESPONSE_BYTES, not JSON, or not shaped as expected, a non-200 or a
 * connection failure refuses with UNVERIFIED; a policy failure with NOT_HOSTED_BY_BLUESKY; a
 * handle Bluesky cannot resolve, or whose document does not claim it back, with HANDLE_NOT_FOUND.
 *
 * A success is cached for CACHE_SECONDS under cacheKey() (a hash of the lower-cased handle), so
 * an alert does not cost two lookups every time; a failure is never cached.
 *
 * Residual risks, by design. TOCTOU: Apprise does its own resolution after this check (and may
 * keep the endpoint in its own persistent store), so an owner who changes the DID document
 * between our check and Apprise's lookup (or within the cache window) can still redirect that
 * one send. The real mitigation is network-level egress control of the Apprise container, the
 * owner/ops item already recorded for the chat types. Rate limits: Bluesky limits
 * createSession per account per day, and Apprise logs in on every send, so a very busy rule can
 * hit that limit and fail sends until it resets; this is not mitigated.
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

    /** DID documents and handle answers are a few hundred bytes. */
    public const MAX_RESPONSE_BYTES = 65536;

    private const DID_PATTERN = '/^did:plc:[a-z2-7]{24}$/D';

    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * The verified DID of the handle's account.
     *
     * @throws BlueskyAccountRefused
     */
    public function verifiedDid(string $handle): string
    {
        $handle = self::normalizeHandle($handle);
        $key = self::cacheKey($handle);
        $cached = Cache::get($key);

        if (is_string($cached) && preg_match(self::DID_PATTERN, $cached) === 1) {
            return $cached;
        }

        $did = $this->lookUp($handle);
        Cache::put($key, $did, self::CACHE_SECONDS);

        return $did;
    }

    public static function cacheKey(string $handle): string
    {
        return 'bluesky-account:'.hash('sha256', self::normalizeHandle($handle));
    }

    /**
     * Whether a DID document's `serviceEndpoint` is one of Bluesky's own PDS hosts.
     */
    public static function isAllowedPdsEndpoint(mixed $endpoint): bool
    {
        if (! is_string($endpoint) || preg_match('#^https://([a-z0-9.-]+)/?$#D', $endpoint, $matches) !== 1) {
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

    private static function normalizeHandle(string $handle): string
    {
        return strtolower(trim($handle));
    }

    /**
     * @throws BlueskyAccountRefused
     */
    private function lookUp(string $handle): string
    {
        $resolved = $this->fetchJson(self::RESOLVE_HANDLE_URL, ['handle' => $handle], notFoundStatus: 400);
        $did = $resolved['did'] ?? null;

        if (! is_string($did)) {
            throw new BlueskyAccountRefused(self::UNVERIFIED);
        }

        if (preg_match(self::DID_PATTERN, $did) !== 1) {
            throw new BlueskyAccountRefused(self::NOT_HOSTED_BY_BLUESKY);
        }

        $document = $this->fetchJson(self::PLC_DIRECTORY_URL.$did);

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
     * @return array<string, mixed>
     *
     * @throws BlueskyAccountRefused
     */
    private function fetchJson(string $url, array $query = [], ?int $notFoundStatus = null): array
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

        if ($notFoundStatus !== null && $response->status() === $notFoundStatus) {
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

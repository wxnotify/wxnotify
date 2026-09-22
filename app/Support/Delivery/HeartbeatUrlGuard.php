<?php

namespace App\Support\Delivery;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Outbound safety for the staff-set Healthchecks ping URLs. Unlike WebhookUrlGuard it allows
 * plain http and private hosts (a self-hosted Healthchecks on the private network is the point)
 * and refuses only what could reach a cloud metadata service or a link-local address.
 */
class HeartbeatUrlGuard
{
    private const BLOCKED_HOSTS = ['metadata.google.internal', 'metadata.goog'];

    private const CACHE_TTL_MINUTES = 5;

    /**
     * `::ffff:0:0:0/96` is the deprecated IPv4-translated block: written in hex
     * (`::ffff:0:7f00:1`) it sidesteps the dotted `::ffff:a.b.c.d` form handled
     * separately, yet still carries an IPv4 address such as 127.0.0.1. `64:ff9b::/96`
     * is NAT64 and `2002::/16` is 6to4 — both tunnel an embedded IPv4 address.
     *
     * @var list<array{string, int}>
     */
    private const BLOCKED_IPV6_PREFIXES = [['64:ff9b::', 96], ['2002::', 16], ['::ffff:0:0:0', 96]];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  returns the IP addresses of a host name; for tests
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * A human-readable reason the URL must not be called, or null when it is safe.
     *
     * The verdict is cached for a few minutes keyed by a hash of the URL (never the raw
     * URL itself — it contains the Healthchecks ping UUID and must never be persisted)
     * so a hot feed doesn't perform a fresh, uncached DNS lookup on every delivery.
     */
    public function violation(string $url): ?string
    {
        $key = 'heartbeat-url-guard:'.sha1($url);

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), fn (): array => ['violation' => $this->computeViolation($url)])['violation'];
    }

    private function computeViolation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full http:// or https:// URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The URL must start with http:// or https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = rtrim($host, '.');

        if (str_contains($host, '%')) {
            return 'The URL host must not contain a zone identifier.';
        }

        if ($this->isObfuscatedNumericHost($host)) {
            return 'The URL host must be a name or a standard IP address, not a numeric shorthand.';
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'That host is a cloud metadata service.';
        }

        // Our lookup and Guzzle's own are separate resolutions (DNS-rebinding gap accepted: staff-set URL, GET only, no response body used, no redirects).
        foreach ($this->addressesFor($host) as $address) {
            if ($this->isBlockedAddress($address)) {
                return 'That address is a link-local or cloud metadata address.';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        try {
            $addresses = @gethostbynamel($host) ?: [];

            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }

            return $addresses;
        } catch (Throwable) {
            return [];
        }
    }

    private function isObfuscatedNumericHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+))*$/i', $host) === 1;
    }

    private function isBlockedAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        $normalized = strtolower((string) inet_ntop($packed));

        if (strlen($packed) === 4) {
            return str_starts_with($normalized, '169.254.');
        }

        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $normalized, $mapped) === 1) {
            return $this->isBlockedAddress($mapped[1]);
        }

        if ($normalized === 'fd00:ec2::254' || preg_match('/^fe[89ab][0-9a-f]:/', $normalized) === 1) {
            return true;
        }

        foreach (self::BLOCKED_IPV6_PREFIXES as [$prefix, $bits]) {
            if ($this->matchesPrefix($packed, inet_pton($prefix), $bits)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPrefix(string $packed, string $prefix, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($packed, 0, $bytes) !== substr($prefix, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask);
    }
}

<?php

namespace App\Support\Delivery;

use Closure;

class WebhookUrlGuard
{
    private const BLOCKED_HOST_SUFFIXES = ['.local', '.internal', '.localhost', '.lan', '.home.arpa'];

    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal'];

    /**
     * `::ffff:0:0:0/96` is the deprecated IPv4-translated block: written in hex
     * (`::ffff:0:7f00:1`) it sidesteps the dotted `::ffff:a.b.c.d` form handled
     * below, yet still carries an IPv4 address such as 127.0.0.1 or 10.0.0.5.
     *
     * @var list<array{string, int}>
     */
    private const BLOCKED_IPV6_PREFIXES = [['64:ff9b::', 96], ['2002::', 16], ['fec0::', 10], ['2001::', 32], ['::ffff:0:0:0', 96]];

    public function __construct(private readonly ?Closure $resolver = null) {}

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full https:// URL.';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'The URL must start with https://.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        $port = $parts['port'] ?? 443;
        if ($port !== 443 && $port < 1024) {
            return 'That port is not allowed; use 443 or a port above 1023.';
        }

        if ($this->privateNetworksAllowed()) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'That host name points at a private network address.';
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'That host name points at a private network address.';
            }
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            return 'That host name could not be resolved.';
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return 'That address resolves to a private or reserved network, which is not allowed.';
            }
        }

        return null;
    }

    private function privateNetworksAllowed(): bool
    {
        return config('wxnotify.mode') === 'selfhost' && (bool) config('wxnotify.webhooks.allow_private_networks');
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }

        // Resolver failures raise warnings (which Laravel turns into exceptions); fail closed instead.
        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }

        return $ips;
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

    private function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // IPv6 ranges that embed or tunnel to IPv4 space (NAT64, 6to4, Teredo) or are deprecated site-local.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            foreach (self::BLOCKED_IPV6_PREFIXES as [$prefix, $bits]) {
                if ($this->matchesPrefix($packed, inet_pton($prefix), $bits)) {
                    return false;
                }
            }
        }

        // Carrier-grade NAT 100.64.0.0/10 is not covered by PHP's range flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return false;
            }
        }

        return true;
    }
}

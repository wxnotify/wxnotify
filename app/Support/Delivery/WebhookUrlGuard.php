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

    /**
     * A proxy is reached over http as often as https, and low ports below 1024
     * are otherwise refused outright — but 80 and 443 are ordinary web ports a
     * proxy legitimately listens on, unlike the service ports (22, 25, 6379, …)
     * the low-port rule exists to keep out. Anything at or above 1024 (3128,
     * 8080, 8443, …) is allowed as usual.
     *
     * @var list<int>
     */
    private const ALLOWED_LOW_PROXY_PORTS = [80, 443];

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

        return match ($this->hostRisk($parts['host'])) {
            'name' => 'That host name points at a private network address.',
            'unresolved' => 'That host name could not be resolved.',
            'private' => 'That address resolves to a private or reserved network, which is not allowed.',
            default => null,
        };
    }

    /**
     * A human-readable reason the outbound proxy must not be used, or null when
     * it is safe.
     *
     * The same IP classification as violation(), with three deliberate
     * differences: http is allowed as well as https (proxies commonly speak
     * plain http on the hop the server controls), credentials in the URL are
     * allowed (that is how a proxy is authenticated), and ports 80/443 are
     * allowed alongside everything at or above 1024.
     *
     * Nothing this returns ever repeats the proxy URL back: it is a stored
     * secret, and these messages end up in form errors and delivery logs.
     */
    public function proxyViolation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'Enter a full http:// or https:// proxy URL.';
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The proxy URL must start with http:// or https://.';
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535 || ($port < 1024 && ! in_array($port, self::ALLOWED_LOW_PROXY_PORTS, true)))) {
            return 'That proxy port is not allowed; use 80, 443, or a port above 1023.';
        }

        return match ($this->hostRisk($parts['host'])) {
            'name' => 'That proxy host name points at a private network address.',
            'unresolved' => 'The proxy host name could not be resolved.',
            'private' => 'The proxy address resolves to a private or reserved network, which is not allowed.',
            default => null,
        };
    }

    /**
     * Why the host must not be reached — 'name', 'unresolved' or 'private' —
     * or null when it is safe. Callers turn the reason into their own wording;
     * the classification itself is shared so the IP-range rules exist once.
     */
    private function hostRisk(string $host): ?string
    {
        if ($this->privateNetworksAllowed()) {
            return null;
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'name';
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'name';
            }
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            return 'unresolved';
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return 'private';
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

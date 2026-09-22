<?php

namespace App\Support\Delivery;

use Closure;

/**
 * Outbound safety for the staff-set Healthchecks ping URLs. Unlike WebhookUrlGuard it allows
 * plain http and private hosts (a self-hosted Healthchecks on the private network is the point)
 * and refuses only what could reach a cloud metadata service or a link-local address.
 */
class HeartbeatUrlGuard
{
    private const BLOCKED_HOSTS = ['metadata.google.internal', 'metadata.goog'];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  returns the IP addresses of a host name; for tests
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
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

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'That host is a cloud metadata service.';
        }

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

        $addresses = gethostbynamel($host) ?: [];

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
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

        return $normalized === 'fd00:ec2::254'
            || preg_match('/^fe[89ab][0-9a-f]:/', $normalized) === 1;
    }
}

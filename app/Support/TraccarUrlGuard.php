<?php

namespace App\Support;

use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Delivery\PinnedHost;
use App\Support\Delivery\PlainAsciiHost;
use App\Support\Delivery\WebhookUrlGuard;
use Closure;

/**
 * Outbound safety for subscriber-supplied Traccar server URLs. Every Traccar
 * request carries the server's Bearer token, so a URL aimed at an internal
 * address would both probe the network and hand the token to whatever answers.
 *
 * Strict by default (the hosted cloud product): no loopback, private, reserved
 * or metadata targets. Only mode `selfhost` relaxes that, because a Traccar on
 * the same network as WxNotify is the expected self-hosted setup: it then defers
 * to HeartbeatUrlGuard, which allows private hosts and refuses only cloud-metadata
 * and link-local targets. Both modes require http(s) and forbid credentials in
 * the URL, require a plain-ASCII host (curl converts IDN and percent-decodes host
 * names, so the guard and the client would otherwise look up different names) and
 * forbid a query or fragment (the app appends API paths to the base URL).
 * Messages are fixed strings and never repeat any part of the URL.
 *
 *
 * A host name is looked up once and the connection is pinned to that answer:
 * requestOptions() hands the HTTP client the vetted addresses as CURLOPT_RESOLVE,
 * so a DNS server that answers the guard with a public address and curl with a
 * private one (DNS rebinding) cannot redirect the request. Every Traccar request
 * must be sent with those options. Pinning is a no-op behind an HTTP proxy, which
 * does its own lookup.
 */
final class TraccarUrlGuard
{
    private readonly HeartbeatUrlGuard $heartbeat;

    private readonly WebhookUrlGuard $webhook;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  returns the IP addresses of a host name; for tests
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->heartbeat = new HeartbeatUrlGuard($resolver);
        $this->webhook = new WebhookUrlGuard($resolver);
    }

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
    {
        return $this->inspect($url)['violation'];
    }

    /**
     * Guzzle options for sending a request to this URL, or null when it must not
     * be called (the same refusals as violation(), plus a host name that does not
     * resolve: curl would then do its own, unvetted, lookup). A host name is pinned
     * to exactly the addresses that passed the check; an IP-literal host needs no
     * pin and gets no extra options.
     *
     * @return array<string, mixed>|null
     */
    public function requestOptions(string $url): ?array
    {
        ['violation' => $violation, 'addresses' => $addresses] = $this->inspect($url);

        if ($violation !== null) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) $parts['host']);

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        if ($addresses === []) {
            return null;
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        return PinnedHost::options($host, [$port], $addresses);
    }

    /**
     * @return array{violation: ?string, addresses: list<string>} the verdict and, for a host name, the
     *                                                            addresses it was judged on
     */
    private function inspect(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return $this->refused('Enter a full http:// or https:// URL.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $this->refused('The URL must start with http:// or https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->refused('The URL must not contain a username or password.');
        }

        if (($asciiViolation = PlainAsciiHost::violation($parts['host'])) !== null) {
            return $this->refused($asciiViolation);
        }

        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            return $this->refused('The URL must not contain a query string or fragment.');
        }

        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (config('wxnotify.mode') === 'selfhost') {
            return $this->inspectSelfhost($url, $parts['scheme'], $host, $isLiteral);
        }

        if ($this->isNumericShorthandHost($host)) {
            return $this->refused('The URL host must be a name or a standard IP address, not a numeric shorthand.');
        }

        $addresses = $isLiteral ? [] : $this->webhook->resolve($host);

        return [
            'violation' => match ($this->webhook->hostRisk($host, $addresses)) {
                'name' => 'That host name points at a private network address.',
                'unresolved' => 'That host name could not be resolved.',
                'private' => 'That address resolves to a private or reserved network, which is not allowed.',
                default => null,
            },
            'addresses' => PinnedHost::normalize($addresses),
        ];
    }

    /**
     * Self-hosted: private hosts are fine, but the heartbeat guard's refusals
     * (metadata, link-local) apply to the URL and to every address the name
     * resolves to, since those are the addresses the request is pinned to.
     *
     * @return array{violation: ?string, addresses: list<string>}
     */
    private function inspectSelfhost(string $url, string $scheme, string $host, bool $isLiteral): array
    {
        $violation = $this->heartbeat->violation($url);

        if ($violation !== null || $isLiteral) {
            return ['violation' => $violation, 'addresses' => []];
        }

        $addresses = PinnedHost::normalize($this->webhook->resolve($host));

        foreach ($addresses as $address) {
            $literal = str_contains($address, ':') ? "[{$address}]" : $address;
            $violation = $this->heartbeat->violation(strtolower($scheme)."://{$literal}/");

            if ($violation !== null) {
                return $this->refused($violation);
            }
        }

        return ['violation' => null, 'addresses' => $addresses];
    }

    /**
     * @return array{violation: string, addresses: list<string>}
     */
    private function refused(string $message): array
    {
        return ['violation' => $message, 'addresses' => []];
    }

    private function isNumericShorthandHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+))*$/i', $host) === 1;
    }
}

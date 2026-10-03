<?php

namespace App\Support;

use App\Support\Delivery\HeartbeatUrlGuard;
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
 * The guard's DNS lookup and the HTTP client's own lookup are separate (the same
 * accepted TOCTOU gap as WebhookUrlGuard).
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
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'Enter a full http:// or https:// URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The URL must start with http:// or https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        if (preg_match('/[^A-Za-z0-9.\-_:\[\]]/', $parts['host']) === 1) {
            return 'Enter the server host name in plain ASCII (use the xn-- form for international names).';
        }

        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            return 'The URL must not contain a query string or fragment.';
        }

        if (config('wxnotify.mode') === 'selfhost') {
            return $this->heartbeat->violation($url);
        }

        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');

        if ($this->isNumericShorthandHost($host)) {
            return 'The URL host must be a name or a standard IP address, not a numeric shorthand.';
        }

        return match ($this->webhook->hostRisk($host)) {
            'name' => 'That host name points at a private network address.',
            'unresolved' => 'That host name could not be resolved.',
            'private' => 'That address resolves to a private or reserved network, which is not allowed.',
            default => null,
        };
    }

    private function isNumericShorthandHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+))*$/i', $host) === 1;
    }
}

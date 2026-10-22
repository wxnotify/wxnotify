<?php

namespace App\Support\Delivery;

use Closure;

/**
 * Vets a subscriber-supplied chat server (Mattermost, Rocket.Chat, Matrix) before WxNotify's
 * Apprise container is pointed at it, at save time (SafeChatServerUrl and each type's
 * buildDestination()) and again before every send (MessagingTransport).
 *
 * The rules, in order: the URL parses (no whitespace, control characters or backslashes, and
 * PHP's parse agrees with a plain split of the authority); https only (http with port 80 or
 * above 1023 only when private networks are allowed); no userinfo; no query or fragment; a
 * plain-ASCII host (PlainAsciiHost); a domain name with at least two labels whose last label is
 * not a number (so `2130706433`, `127.1` and `0x7f.1`, which resolvers read as IPv4, are
 * refused), or a bracketed IPv6 literal, or a dotted-quad IPv4 literal; port 443 or 1024-65535;
 * and an address WebhookUrlGuard::hostRisk() calls public, where a name must resolve and every
 * address it resolves to must be public. Blocked names (`localhost`, `.internal`, `.local`, ...)
 * are refused before any lookup. Addresses are canonicalised first, so `::ffff:7f00:1` is judged
 * as `::ffff:127.0.0.1`. The path is not judged here: each type validates its own.
 *
 * IPv6 literals are allowed: Apprise 2.0.0 keeps the brackets in the request URL it builds
 * (spiked for `mmosts://`, `matrixs://` and `rockets://`).
 *
 * Residual risk, by design (spec decision 15): Apprise does its own DNS lookup when it sends,
 * and its connection cannot be pinned to the address vetted here without breaking TLS, so a
 * hostile DNS server can answer this guard with a public address and Apprise with a private one
 * (DNS rebinding). The fix is at the network level (an egress-filtering proxy or firewall rules
 * for the Apprise container). Redirects are closed by the types' `redirect=no` URL argument.
 *
 * Messages are fixed strings and never repeat any part of the URL.
 */
final class ChatServerGuard
{
    public const NEEDS_URL = "Enter the server's full https:// address.";

    public const HTTPS_ONLY = 'The address must start with https://.';

    public const NO_USERINFO = 'The address must not contain a username or password.';

    public const NO_QUERY = 'Remove the ?query or #fragment from the address.';

    public const NEEDS_DOMAIN = "Enter the server's full domain name, e.g. chat.example.com.";

    public const BAD_PORT = 'That port is not allowed; use 443 or a port above 1023.';

    public const NOT_FOUND = 'That server could not be found.';

    public const PRIVATE_NETWORK = 'That server is on a private or reserved network, which is not allowed.';

    private readonly WebhookUrlGuard $webhookGuard;

    public function __construct(?Closure $resolver = null)
    {
        $this->webhookGuard = new WebhookUrlGuard($resolver);
    }

    /** Why WxNotify must not send to this server, or null when it may. */
    public function violation(string $url): ?string
    {
        $parts = $this->parse($url);

        if ($parts === null) {
            return self::NEEDS_URL;
        }

        $privateAllowed = $this->privateNetworksAllowed();
        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $privateAllowed)) {
            return self::HTTPS_ONLY;
        }
        if ($parts['userinfo']) {
            return self::NO_USERINFO;
        }
        if (strpbrk($url, '?#') !== false) {
            return self::NO_QUERY;
        }
        if (($asciiViolation = PlainAsciiHost::violation($parts['host'])) !== null) {
            return $asciiViolation;
        }

        $host = $this->judgedHost($parts['host']);

        if ($host === null) {
            return self::NEEDS_DOMAIN;
        }
        if (! $this->portAllowed($scheme, $parts['port'])) {
            return self::BAD_PORT;
        }

        return $this->addressViolation($host);
    }

    /** Self-host mode with the private-network flag set; the cloud ignores the flag. */
    public function privateNetworksAllowed(): bool
    {
        return config('wxnotify.mode') === 'selfhost' && (bool) config('wxnotify.webhooks.allow_private_networks');
    }

    /**
     * The scheme, the authority's host and port, and whether it carries userinfo, or null when
     * the URL is not a full absolute URL. The authority is split by hand as well as by
     * parse_url(), and the two must agree, so a URL the parsers could read differently is refused.
     *
     * @return array{scheme: string, host: string, port: ?string, userinfo: bool}|null
     */
    private function parse(string $url): ?array
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://([^/?\#]*)#', $url, $matches) !== 1) {
            return null;
        }

        [, $scheme, $authority] = $matches;
        $userinfo = str_contains($authority, '@');

        if ($userinfo) {
            return ['scheme' => $scheme, 'host' => '', 'port' => null, 'userinfo' => true];
        }
        if (preg_match('/^(\[[^\]]*\]|[^:\[\]]*)(?::([^:]*))?$/', $authority, $hostPort) !== 1 || $hostPort[1] === '') {
            return null;
        }

        // The port is judged from the split above (parse_url() gives up on an out-of-range one).
        $parsedHost = parse_url($scheme.'://'.$hostPort[1].substr($url, strlen($matches[0])), PHP_URL_HOST);

        if (! is_string($parsedHost) || strtolower($parsedHost) !== strtolower($hostPort[1])) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $hostPort[1], 'port' => $hostPort[2] ?? null, 'userinfo' => false];
    }

    /**
     * The host as it is judged and looked up (lower case, one trailing dot dropped, an IP
     * literal canonicalised), or null when it is not a usable domain name or IP literal.
     */
    private function judgedHost(string $host): ?string
    {
        $host = strtolower($host);

        if (str_starts_with($host, '[')) {
            $inner = substr($host, 1, -1);

            return str_ends_with($host, ']') && filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? $this->canonicalAddress($inner)
                : null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        $name = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $labels = explode('.', $name);

        if (count($labels) < 2 || in_array('', $labels, true) || preg_match('/^(\d+|0x[0-9a-f]*)$/', end($labels)) === 1) {
            return null;
        }

        return $name;
    }

    /** 443 or 1024-65535; http (self-host only) takes 80 or 1024-65535. */
    private function portAllowed(string $scheme, ?string $port): bool
    {
        if ($port === null) {
            return true;
        }
        if (preg_match('/^\d{1,5}$/', $port) !== 1) {
            return false;
        }

        $number = (int) $port;

        return ($number >= 1024 && $number <= 65535) || $number === ($scheme === 'http' ? 80 : 443);
    }

    /**
     * Blocked names are refused before any lookup; a name must resolve, and every address it
     * resolves to (and an IP literal) must be public, unless private networks are allowed.
     */
    private function addressViolation(string $host): ?string
    {
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $isLiteral && $this->webhookGuard->hostRisk($host, []) === 'name') {
            return self::PRIVATE_NETWORK;
        }

        $addresses = $isLiteral ? [] : array_map($this->canonicalAddress(...), $this->webhookGuard->resolve($host));

        if (! $isLiteral && $addresses === []) {
            return self::NOT_FOUND;
        }

        return match ($this->webhookGuard->hostRisk($host, $addresses)) {
            null => null,
            'unresolved' => self::NOT_FOUND,
            default => self::PRIVATE_NETWORK,
        };
    }

    /**
     * One spelling per address (`::ffff:7f00:1` becomes `::ffff:127.0.0.1`), so the dotted
     * IPv4-mapped check sees every mapped address. Anything that is not an address is kept as
     * given, and hostRisk() then refuses it as not public.
     */
    private function canonicalAddress(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? $address : (string) inet_ntop($packed);
    }
}

<?php

namespace App\Support\Delivery;

/**
 * One spelling of a subscriber-supplied chat server address (`scheme://host[:port]`), shared by
 * the guarded messaging types (Mattermost, Rocket.Chat, Matrix): what they store as `server`,
 * the authority they put in the Apprise URL, and the host part of their destination key.
 *
 * Lower-case scheme and host, one trailing dot and one trailing slash dropped, an IPv6 literal
 * canonicalised with its brackets kept (`[2606:4700:0::1]` is `[2606:4700::1]`), and the
 * scheme's default port (443, 80) dropped. Only http and https are read. This only spells the
 * address: whether WxNotify may send to it is ChatServerGuard's call.
 */
final class ChatServerAddress
{
    private const PATTERN = '#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?/?$#D';

    /**
     * The scheme, host and port (null for the scheme's default), or null when the string is
     * not such an address.
     *
     * @return array{scheme: string, host: string, port: ?int}|null
     */
    public static function parts(string $server): ?array
    {
        if (preg_match(self::PATTERN, strtolower($server), $matches) !== 1) {
            return null;
        }

        [$scheme, $host] = [$matches[1], $matches[2]];

        if (str_starts_with($host, '[')) {
            $packed = @inet_pton(substr($host, 1, -1));

            if ($packed === false) {
                return null;
            }

            $host = '['.inet_ntop($packed).']';
        } elseif (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '') {
            return null;
        }

        $port = isset($matches[3]) ? (int) $matches[3] : null;

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port === self::defaultPort($scheme) ? null : $port];
    }

    /**
     * `scheme://host[:port]`.
     *
     * @param  array{scheme: string, host: string, port: ?int}  $parts
     */
    public static function toUrl(array $parts): string
    {
        return "{$parts['scheme']}://".self::authority($parts);
    }

    /**
     * `host[:port]`, the port written only when it is not the scheme's default.
     *
     * @param  array{scheme: string, host: string, port: ?int}  $parts
     */
    public static function authority(array $parts): string
    {
        return $parts['host'].($parts['port'] === null ? '' : ':'.$parts['port']);
    }

    /**
     * `host:port` with the port always written, for destination keys.
     *
     * @param  array{scheme: string, host: string, port: ?int}  $parts
     */
    public static function hostAndPort(array $parts): string
    {
        return $parts['host'].':'.($parts['port'] ?? self::defaultPort($parts['scheme']));
    }

    public static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }
}

<?php

namespace App\Support;

/**
 * Reads the product page out of a push notification's app link (wxnotify://product/{link}): the link is the
 * base64url of a public View Product page address. Any app or web page on the phone can open a custom-scheme
 * link, so nothing but an https View Product page on the server's own site (its host or a subdomain of the
 * same two-label domain, since pages can be served from a product subdomain) is ever opened.
 */
final class ProductLinkTarget
{
    /**
     * The page address, or null for anything that is not a View Product page of this server.
     */
    public static function pageFrom(string $link): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $link) !== 1) {
            return null;
        }

        $page = base64_decode(strtr($link, '-_', '+/'), true);
        $parts = $page === false ? false : parse_url($page);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || array_intersect_key($parts, array_flip(['user', 'pass', 'port', 'query', 'fragment'])) !== []
            || preg_match('#^/view-product/[a-z0-9-]+$#', $parts['path'] ?? '') !== 1) {
            return null;
        }

        return self::isOwnSite($parts['host']) ? $page : null;
    }

    private static function isOwnSite(string $host): bool
    {
        $server = parse_url((string) config('wxnotify.api_url'), PHP_URL_HOST);

        if (! is_string($server) || $server === '') {
            return false;
        }

        $site = implode('.', array_slice(explode('.', strtolower($server)), -2));
        $host = strtolower($host);

        return $host === $site || str_ends_with($host, ".{$site}");
    }
}

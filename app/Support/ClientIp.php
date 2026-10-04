<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * The address of the client that made a request, including behind a load balancer
 * or Cloudflare.
 *
 * By default this is `$request->ip()`, the connecting address, and every forwarding
 * header is ignored, because any client can send one. Two environment settings opt in
 * to trusting a proxy, and a header is only believed when the connection itself comes
 * from a trusted proxy:
 *
 *  - WXNOTIFY_TRUSTED_PROXIES: `*`, or a comma list of proxy IPs / CIDRs. Application-
 *    wide (it configures Laravel's TrustProxies), so `$request->ip()` then honors
 *    `X-Forwarded-For` everywhere: rate limiting, logs and activity alerts.
 *  - WXNOTIFY_CLIENT_IP_HEADER: a header to prefer over that, `CF-Connecting-IP` for
 *    Cloudflare. A missing or non-IP value falls back to `$request->ip()`.
 *
 * Behind Cloudflare, list Cloudflare's published ranges (or the load balancer that sits
 * behind it) as the trusted proxies, and make sure the origin cannot be reached any
 * other way: a client that can reach it directly could forge the header.
 */
final class ClientIp
{
    /** Applies WXNOTIFY_TRUSTED_PROXIES to Laravel's TrustProxies middleware. */
    public static function configureProxies(): void
    {
        $configured = config('wxnotify.client_ip.trusted_proxies');

        if (blank($configured)) {
            return;
        }

        $configured = trim((string) $configured);

        TrustProxies::at($configured === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $configured)))));
    }

    /** The client's address, or null when the request has none (console, queue, scheduler). */
    public static function for(Request $request): ?string
    {
        // Applies the configured trusted proxies to this request, as the global middleware
        // does for every web request. Idempotent, and it makes this safe to call anywhere.
        (new TrustProxies)->handle($request, fn (): null => null);

        $header = config('wxnotify.client_ip.header');

        if (filled($header) && $request->isFromTrustedProxy()) {
            $value = trim((string) $request->header((string) $header));

            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        $ip = $request->ip();

        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /**
     * The client address for an activity alert about `$subject`: only when the request
     * is the subscriber's own (a guest request such as sign-in, sign-out or registration,
     * or one made while signed in as them). A request made by staff, or by staff
     * impersonating the subscriber, carries staff's address, not the subscriber's.
     */
    public static function forActivity(User $subject, ?Request $request = null): ?string
    {
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return null;
        }

        $actorId = auth()->id();

        if ($actorId !== null && $actorId !== $subject->getKey()) {
            return null;
        }

        return self::for($request ?? request());
    }
}

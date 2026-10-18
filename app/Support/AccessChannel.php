<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * How the subscriber reached the app on a request: `web` for the browser (any session request,
 * including the sign-in page the mobile app opens for its browser sign-in) or `mobile app` for a
 * request carrying the WxNotify app's token. Anything else (a public API key, a location beacon, the
 * console, the queue, the scheduler) says nothing.
 */
final class AccessChannel
{
    public const WEB = 'web';

    public const MOBILE_APP = 'mobile app';

    public static function for(Request $request): ?string
    {
        $user = $request->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            return $token->can('app') ? self::MOBILE_APP : null;
        }

        return $request->hasSession() && $request->route() !== null ? self::WEB : null;
    }

    /**
     * The channel for an activity alert about `$subject`: only when the request is the subscriber's own,
     * as for ClientIp::forActivity(). A request made by staff, or by staff impersonating the subscriber,
     * says nothing about how the subscriber accesses the app.
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

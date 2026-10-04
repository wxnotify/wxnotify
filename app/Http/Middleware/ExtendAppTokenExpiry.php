<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an app token alive for as long as it is used: each authenticated request pushes
 * its expiry back to the full lifetime, but only once a day (at most one write per token
 * per day), so an unused phone's token still lapses after `token_days`.
 */
class ExtendAppTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->expires_at !== null) {
            $target = now()->addDays((int) config('wxnotify.app_auth.token_days'));

            if ($token->expires_at->lt($target->copy()->subDay())) {
                $token->forceFill(['expires_at' => $target])->save();
            }
        }

        return $next($request);
    }
}

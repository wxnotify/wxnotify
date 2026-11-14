<?php

namespace App\Actions\AppAuth;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Trades an authorization code plus its PKCE verifier for an app token. The code is consumed
 * first, under a lock, so any attempt (right or wrong) burns it and two concurrent requests
 * cannot both succeed; every failure returns null, and the caller answers them all alike.
 */
class ExchangeAppAuthorizationCode
{
    /**
     * @return array{token: string, expires_at: CarbonInterface, user: User}|null
     */
    public function __invoke(string $code, string $verifier): ?array
    {
        $key = IssueAppAuthorizationCode::key($code);

        $result = Cache::lock($key.':lock', 5)->get(function () use ($key, $verifier): ?array {
            $grant = Cache::pull($key);

            if (! is_array($grant)) {
                return null;
            }

            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            if (! hash_equals($grant['challenge'], $expected)) {
                return null;
            }

            $user = User::find($grant['user_id']);

            if ($user === null || ! $user->hasVerifiedEmail() || $user->isPendingDeletion()) {
                return null;
            }

            $expiresAt = now()->addDays((int) config('wxnotify.app_auth.token_days'));
            $token = $user->createToken($grant['device_name'], ['app'], $expiresAt);

            // This request has no session user, and the app's CauserResolver override ignores
            // causedBy(), so pin the subscriber as the causer for this one entry.
            app(CauserResolver::class)->withCauser($user, fn () => activity()
                ->performedOn($user)
                ->event('app-signed-in')
                ->withProperties(['device' => $grant['device_name']])
                ->log('Signed in to the WxNotify app'));

            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt, 'user' => $user];
        });

        return is_array($result) ? $result : null;
    }
}

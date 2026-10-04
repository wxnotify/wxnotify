<?php

namespace App\Actions\AppAuth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mints the single-use code the mobile app trades for a token. The cache holds it under the
 * SHA-256 of the code (a leaked cache dump cannot be replayed) for a couple of minutes,
 * together with the PKCE challenge and the device name the subscriber just approved.
 */
class IssueAppAuthorizationCode
{
    public function __invoke(User $user, string $challenge, string $deviceName): string
    {
        $code = Str::random(64);

        Cache::put(
            self::key($code),
            ['user_id' => $user->id, 'challenge' => $challenge, 'device_name' => $deviceName],
            now()->addSeconds((int) config('wxnotify.app_auth.code_ttl_seconds')),
        );

        return $code;
    }

    public static function key(string $code): string
    {
        return 'app-auth-code:'.hash('sha256', $code);
    }
}

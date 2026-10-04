<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * Who is signed in, their plan and the app version, plus sign out.
 */
class AccountScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);
        $this->profile = $cache->profile();

        try {
            $this->profile = app(WxNotifyApi::class)->me();
            $cache->rememberProfile($this->profile);
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (ApiException) {
            // Offline or a server hiccup: the remembered profile stays on screen.
        }
    }

    public function signOut(): void
    {
        app(SignOut::class)();

        $this->replace('/sign-in');
    }

    public function appVersion(): string
    {
        return (string) config('nativephp.version');
    }
}

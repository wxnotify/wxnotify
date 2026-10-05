<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Unauthenticated;
use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\PushState;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\System;

/**
 * Who is signed in, their plan and the app version, plus sign out.
 */
class AccountScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    public ?string $pushMessage = null;

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

    /**
     * 'on', 'off' or 'blocked' (the OS refused notifications, so only the system settings can allow them again).
     */
    public function pushStatus(): string
    {
        if (app(PushRegistration::class)->permission() === 'denied') {
            return 'blocked';
        }

        return $this->pushWanted() ? 'on' : 'off';
    }

    /**
     * Whether the subscriber still has push turned on, whatever the OS permission is; a blocked
     * phone that is still wanted has a server channel that "Turn off" removes.
     */
    public function pushWanted(): bool
    {
        return app(PushState::class)->wantsPush();
    }

    public function pushStatusLabel(string $status): string
    {
        return match ($status) {
            'on' => 'On',
            'blocked' => 'Blocked in system settings',
            default => 'Off',
        };
    }

    public function enablePush(): void
    {
        $this->pushMessage = null;

        app(PushRegistration::class)->enable();
    }

    public function disablePush(): void
    {
        try {
            $this->pushMessage = app(PushRegistration::class)->disable()
                ? null
                : 'Couldn\'t turn push off. Check your connection and try again.';
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        }
    }

    public function openSystemSettings(): void
    {
        System::appSettings();
    }

    public function appVersion(): string
    {
        return (string) config('nativephp.version');
    }
}

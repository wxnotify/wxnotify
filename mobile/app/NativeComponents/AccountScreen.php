<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Unauthenticated;
use App\Services\LocationSharing;
use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\OpensSettingsMenu;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\PushState;
use Illuminate\Support\Carbon;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\System;
use Throwable;

/**
 * Who is signed in, their plan and the app version, plus sign out.
 */
class AccountScreen extends NativeComponent
{
    use HandlesPushToken;
    use OpensSettingsMenu;
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    public ?string $pushMessage = null;

    public ?string $locationMessage = null;

    protected function settingsPage(): ?string
    {
        return '/account';
    }

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

    /**
     * @return array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}
     */
    public function locationState(): array
    {
        return app(LocationSharing::class)->state();
    }

    /**
     * @param  array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}  $state  the state the view is drawing, so the label and the buttons agree
     */
    public function locationStatusLabel(array $state): string
    {
        return match ($state['status']) {
            'on' => 'On — about every '.($state['interval_minutes'] ?? 5).' minutes'.$this->lastSharedSuffix($state['last_shared_at']),
            'waiting' => 'Waiting for location permission',
            default => 'Off',
        };
    }

    private function lastSharedSuffix(?string $lastSharedAt): string
    {
        if ($lastSharedAt === null) {
            return '';
        }

        try {
            return ' · last shared '.Carbon::parse($lastSharedAt)
                ->setTimezone($this->profile['timezone'] ?? config('app.timezone'))
                ->format('g:i a');
        } catch (Throwable) {
            return '';
        }
    }

    public function shareLocation(): void
    {
        $this->locationMessage = null;

        $this->navigate('/share-location');
    }

    public function stopSharingLocation(): void
    {
        $this->locationMessage = app(LocationSharing::class)->disable()
            ? null
            : 'Couldn\'t stop sharing. Check your connection and try again.';
    }

    public function openLocationSettings(): void
    {
        System::appSettings();
    }

    /**
     * Back from the system settings or the explainer: the native watch may have changed, and the
     * row reads it fresh on every render, so there is nothing to refetch.
     */
    public function onResume(): void {}

    public function appVersion(): string
    {
        return (string) config('nativephp.version');
    }
}

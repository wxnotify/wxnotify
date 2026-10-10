<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\OpensSettingsMenu;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's locations: stationary places and the mobile ones that follow a phone or a
 * Traccar device, each with how current it is. Paints from the cache first, refreshes, and keeps
 * the last copy with a note when offline.
 */
class LocationsScreen extends NativeComponent
{
    use HandlesPushToken;
    use OpensSettingsMenu;
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $locations = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public bool $offline = false;

    /** Whether the list on screen is known (remembered or just loaded): only then can it be "empty". */
    public bool $loaded = false;

    public ?string $error = null;

    public ?string $message = null;

    protected function settingsPage(): ?string
    {
        return '/locations';
    }

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);
        $cached = $cache->locations();
        $this->locations = $cached ?? [];
        $this->meta = $cache->locationsMeta() ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the editor (saved, deleted or abandoned): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    /**
     * Offline or failing with nothing remembered leaves the list unknown, so no "no locations yet".
     */
    public function refresh(): void
    {
        $this->message = null;

        try {
            $page = app(WxNotifyApi::class)->locations();
            $this->locations = $page['data'];
            $this->meta = $page['meta'];
            app(LocalCache::class)->rememberLocations($this->locations, $this->meta);
            $this->offline = false;
            $this->error = null;
            $this->loaded = true;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
        } catch (Maintenance $e) {
            $this->offline = false;
            $this->error = $e->getMessage();
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load your locations. Try again in a moment.';
        }
    }

    /**
     * At the plan limit the add button explains the limit instead of opening an editor that would fail.
     */
    public function create(): void
    {
        $this->message = null;

        if (($this->meta['can_create'] ?? true) === false) {
            $limit = $this->meta['limit'] ?? null;
            $this->message = 'Your plan allows '.$limit.' location'.($limit === 1 ? '' : 's').'. Remove one, or upgrade, to add another.';

            return;
        }

        $this->navigate('/locations/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/locations/{$id}");
    }

    /**
     * What kind of location this is and how current it is, in a few words.
     *
     * @param  array<string, mixed>  $location
     */
    public function subtitle(array $location): string
    {
        if ($location['type'] === 'stationary') {
            return filled($location['address'] ?? null) ? (string) $location['address'] : 'Stationary';
        }

        $source = (string) ($location['source_label'] ?? 'Mobile');

        return match (true) {
            $location['source'] === 'app' && $location['linked'] === false => "{$source} · Not linked to a phone",
            $location['source'] === 'traccar' && $location['has_missing_device'] => "{$source} · Device missing",
            ! $location['has_fix'] => "{$source} · Waiting for a first position",
            $location['stale'] === true => "{$source} · No recent update",
            default => "{$source} · Current",
        };
    }
}

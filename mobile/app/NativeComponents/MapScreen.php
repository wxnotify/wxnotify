<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\MapPage;
use Illuminate\Support\Carbon;
use JsonException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The Map tab: a Leaflet page in a web view (radar, the warnings and watches near the phone and the
 * places, the places and the phone), and a native "Near you" list that opens a warning's product page
 * or a place. The map page is display-only. Its HTML is rebuilt only when the snapshot changes, so
 * the web view is not reloaded (and pan and zoom not lost) by anything else.
 */
class MapScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed> the snapshot's list data (never the geometry) */
    public array $snapshot = [];

    /** The web view's page; null until a snapshot has been fetched this visit. */
    public ?string $html = null;

    public bool $offline = false;

    public bool $loaded = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->map();
        $this->snapshot = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $snapshot = app(WxNotifyApi::class)->map();
            $page = app(MapPage::class)->html($snapshot);
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');

            return;
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
            $this->html = null;

            return;
        } catch (Maintenance $e) {
            $this->offline = false;
            $this->error = $e->getMessage();

            return;
        } catch (ApiException|JsonException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load the map. Try again in a moment.';

            return;
        }

        app(LocalCache::class)->rememberMap($snapshot);

        if ($page !== $this->html) {
            $this->html = $page;
        }

        $this->snapshot = app(LocalCache::class)->map() ?? [];
        $this->offline = false;
        $this->error = null;
        $this->loaded = true;
    }

    public function openWarning(int $index): void
    {
        $url = $this->snapshot['warnings'][$index]['view_url'] ?? null;

        if (filled($url)) {
            Browser::inApp($url);
        }
    }

    public function openPlace(int $id): void
    {
        $this->navigate("/locations/{$id}");
    }

    /**
     * @param  array<string, mixed>  $warning
     */
    public function warningSubtitle(array $warning): string
    {
        return filled($warning['expires'] ?? null)
            ? 'Ends '.Carbon::parse($warning['expires'])->diffForHumans()
            : 'No end time given';
    }

    /**
     * @param  array<string, mixed>  $place
     */
    public function placeSubtitle(array $place): string
    {
        return $place['stale'] ? 'No recent update' : ($place['type'] === 'mobile' ? 'Current' : 'Saved place');
    }

    public function hasNothingToShow(): bool
    {
        return $this->loaded && ($this->snapshot['places'] ?? []) === [] && ($this->snapshot['warnings'] ?? []) === [] && ($this->snapshot['phone'] ?? null) === null;
    }
}

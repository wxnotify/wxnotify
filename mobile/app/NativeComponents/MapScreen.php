<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\ClearsPushBadge;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\MapPage;
use JsonException;
use Native\Mobile\Edge\NativeComponent;

/**
 * The Map tab: a Leaflet page (radar, the warnings and watches near the phone and the places, the
 * places and the phone) in a web view that fills the screen. The map page is display-only. The page is
 * served to the web view by the `/map-page` route (PHP mode) and its URL changes only when the snapshot
 * does, so the web view is not reloaded (and pan and zoom not lost) by anything else. The snapshot is
 * fetched when the screen opens.
 */
class MapScreen extends NativeComponent
{
    use ClearsPushBadge;
    use RequiresSignIn;

    /** The web view's page route, versioned by the page's content; null until a snapshot has been fetched this visit. */
    public ?string $pageUrl = null;

    public bool $offline = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->clearPushBadgeOnOpen();

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
            $this->pageUrl = null;

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

        // The page is deterministic per snapshot, so an unchanged snapshot yields the same version and URL and the web view is not reloaded.
        $this->pageUrl = '/map-page?v='.app(LocalCache::class)->rememberMapPage($page);

        $this->offline = false;
        $this->error = null;
    }
}

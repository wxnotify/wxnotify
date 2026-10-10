<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Icons\Android;
use App\Icons\Ios;
use App\Services\LocationSharing;
use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Illuminate\Support\Carbon;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The home screen: the subscriber's recent alerts. Paints from the local cache first, then
 * refreshes; offline it keeps showing what it has, with a banner.
 */
class AlertsScreen extends NativeComponent
{
    use HandlesPushToken;
    use RequiresSignIn;

    private const RESUME_REFRESH_AFTER_SECONDS = 60;

    private const LOAD_MORE_DEBOUNCE_SECONDS = 1;

    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    public ?string $nextCursor = null;

    public bool $offline = false;

    public ?string $error = null;

    public ?string $syncedAt = null;

    /** True once a further page has been appended, so coming back to the screen must not reset the list. */
    public bool $pagedBeyondFirst = false;

    /** Re-entrancy guard for loadMore(); it cannot stop a burst of queued end-reached events on its own. */
    public bool $loadingMore = false;

    /** When the last further page finished loading, so loadMore() can ignore calls that follow straight after. */
    public ?string $lastPageLoadedAt = null;

    public ?string $timezone = null;

    /** The Settings tab's menu (a bottom sheet: a bottom-bar item cannot carry a dropdown). */
    public bool $settingsMenuOpen = false;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);

        $this->alerts = $cache->feed();
        $this->timezone = $cache->profile()['timezone'] ?? null;
        $this->syncedAt = $cache->syncedAt()?->toIso8601String();

        $this->refresh();

        app(PushRegistration::class)->refreshIfDue();
        app(LocationSharing::class)->refreshOnLaunch();
    }

    /**
     * NativePHP calls this whenever a pushed screen (an alert's detail) is popped, so it must not
     * throw away pages the subscriber loaded or refetch a feed that was just fetched.
     */
    public function onResume(): void
    {
        if ($this->pagedBeyondFirst) {
            return;
        }

        if ($this->syncedAt !== null && Carbon::parse($this->syncedAt)->diffInSeconds(now(), true) < self::RESUME_REFRESH_AFTER_SECONDS) {
            return;
        }

        $this->refresh();
    }

    public function refresh(): void
    {
        $cache = app(LocalCache::class);

        try {
            $page = app(WxNotifyApi::class)->alerts(limit: (int) config('wxnotify.feed_cache_size'));

            $cache->replaceFeed($page['data']);

            $this->alerts = $page['data'];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->syncedAt = $cache->syncedAt()?->toIso8601String();
            $this->pagedBeyondFirst = false;
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load your alerts. Pull down to try again.';
        }
    }

    /**
     * The list's end-reached handler. The list fires it for every row that appears near the bottom, and
     * the events are queued and handled one after another, so a burst would otherwise load several pages
     * back to back. It does nothing without a next cursor or while a load is running, and ignores calls
     * made within a second of the previous page finishing (scrolling through a new page takes longer).
     */
    public function loadMore(): void
    {
        if ($this->nextCursor === null || $this->loadingMore || $this->loadedAPageJustNow()) {
            return;
        }

        $this->loadingMore = true;

        try {
            $page = app(WxNotifyApi::class)->alerts($this->nextCursor);

            $this->alerts = [...$this->alerts, ...$page['data']];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->pagedBeyondFirst = true;
            $this->lastPageLoadedAt = now()->toRfc3339String(true);
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load more alerts. Try again.';
        } finally {
            $this->loadingMore = false;
        }
    }

    private function loadedAPageJustNow(): bool
    {
        return $this->lastPageLoadedAt !== null
            && Carbon::parse($this->lastPageLoadedAt)->diffInMilliseconds(now(), true) < self::LOAD_MORE_DEBOUNCE_SECONDS * 1000;
    }

    public function openSettingsMenu(): void
    {
        $this->settingsMenuOpen = true;
    }

    public function closeSettingsMenu(): void
    {
        $this->settingsMenuOpen = false;
    }

    public function openRules(): void
    {
        $this->openSettingsPage('/rules');
    }

    public function openLocations(): void
    {
        $this->openSettingsPage('/locations');
    }

    public function openAccount(): void
    {
        $this->openSettingsPage('/account');
    }

    /**
     * Pushes the page over Alerts. The Settings pages carry no tab bar of their own: the bar swallows a tap
     * on the tab it considers active, so Settings could not be tapped again from a page it owned.
     */
    private function openSettingsPage(string $uri): void
    {
        $this->settingsMenuOpen = false;
        $this->navigate($uri);
    }

    /**
     * Opens the alert's public product page in the in-app browser; an alert with no product page
     * (a daily summary, say) or a cached row from before the feed carried the link opens its detail.
     */
    public function open(int $id): void
    {
        $alert = collect($this->alerts)->firstWhere('id', $id);

        if (filled($alert['product_url'] ?? null)) {
            Browser::inApp($alert['product_url']);

            return;
        }

        $this->openDetail($id);
    }

    /**
     * The long-press action: the alert's detail (rule, channels, delivery status), whether or not it
     * has a product page.
     */
    public function openDetail(int $id): void
    {
        $this->navigate("/alerts/{$id}");
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public function subtitle(array $alert): string
    {
        return AlertPresenter::subtitle($alert);
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public function channels(array $alert): string
    {
        return AlertPresenter::channels($alert);
    }

    /**
     * The row's supporting text: the channels line, then a "Delivery problem" line when any send failed.
     *
     * @param  array<string, mixed>  $alert
     */
    public function supporting(array $alert): string
    {
        return $alert['has_failure']
            ? $this->channels($alert)."\nDelivery problem"
            : $this->channels($alert);
    }

    /**
     * A warning-triangle badge on rows with a failed send. The words "Delivery problem" are in the
     * supporting text, so failure is never conveyed by the badge colour alone.
     *
     * @param  array<string, mixed>  $alert
     * @return array<int, array{icon: string, ios: Ios, android: Android, color: string}>
     */
    public function failureBadges(array $alert): array
    {
        if (! $alert['has_failure']) {
            return [];
        }

        return [['icon' => 'warning', 'ios' => Ios::ExclamationmarkTriangleFill, 'android' => Android::Warning, 'color' => '#EF4444']];
    }

    public function syncedLabel(): string
    {
        if ($this->syncedAt === null) {
            return '';
        }

        return AlertPresenter::absolute($this->syncedAt, $this->timezone) ?? AlertPresenter::when($this->syncedAt);
    }
}

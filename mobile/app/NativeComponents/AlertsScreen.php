<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Icons\Android;
use App\Icons\Ios;
use App\Services\AlertReadState;
use App\Services\LocationSharing;
use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Services\Welcome;
use App\Services\WxNotifyApi;
use App\Support\ActiveAlertsPresenter;
use App\Support\AlertPresenter;
use App\Support\Concerns\ClearsPushBadge;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Carbon;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The home screen: the subscriber's recent alerts. Paints from the local cache first, then
 * refreshes; offline it keeps showing what it has, with a banner. Unread alerts carry a dot; opening
 * one reads it and "Mark all as read" reads them all (AlertReadState: local first, server best-effort).
 */
class AlertsScreen extends NativeComponent
{
    use ClearsPushBadge;
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

    /** The subscriber's unread count as this phone last knew it; null until a feed load reported one. */
    public ?int $unreadCount = null;

    /** True once a further page has been appended, so coming back to the screen must not reset the list. */
    public bool $pagedBeyondFirst = false;

    /** Re-entrancy guard for loadMore(); it cannot stop a burst of queued end-reached events on its own. */
    public bool $loadingMore = false;

    /** When the last further page finished loading, so loadMore() can ignore calls that follow straight after. */
    public ?string $lastPageLoadedAt = null;

    public ?string $timezone = null;

    /** @var array<int, array<string, mixed>> The current watches, warnings and advisories at the subscriber's places. */
    public array $activeEvents = [];

    /** False when the server says the subscriber has no located place, so an empty list means something else. */
    public bool $hasPlaces = true;

    /** When the events being shown were fetched (the response's generated_at); null until a copy exists. */
    public ?string $activeFetchedAt = null;

    public bool $activeOffline = false;

    public bool $activeError = false;

    public bool $showAllActive = false;

    /** True once a copy of the section exists (saved or fetched), whatever its metadata says. */
    public bool $hasActiveCopy = false;

    /** When the section was last fetched successfully (this phone's clock, not the server's generated_at). */
    public ?string $activeRefreshedAt = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        app(Welcome::class)->markLegacyInstallSeen();

        $this->clearPushBadgeOnOpen();

        $cache = app(LocalCache::class);

        $this->alerts = $cache->feed();
        $this->timezone = $cache->profile()['timezone'] ?? null;
        $this->syncedAt = $cache->syncedAt()?->toIso8601String();
        $this->unreadCount = $cache->unreadCount();

        $savedActive = $cache->activeAlerts();

        if ($savedActive !== null) {
            $this->applyActive($savedActive);
        }

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
        $this->applyCachedReadState();

        if (! $this->pagedBeyondFirst && ! $this->feedSyncedRecently()) {
            $this->refreshFeed();
        }

        $this->refreshActiveWhenStale();
    }

    /**
     * Nothing re-renders this screen when the app returns to the foreground, so a phone left on it would
     * keep showing an event that has ended (and miss a new one). Every minute this re-renders, which
     * re-applies the ended-event filter and the relative "Ends ..." text, and refetches the section when
     * its copy is a minute old. The sent-alerts feed is never touched.
     */
    #[Poll(60000)]
    public function pollActive(): void
    {
        $this->refreshActiveWhenStale();
    }

    /**
     * Refetches the section when the last successful fetch is missing or at least a minute old.
     */
    private function refreshActiveWhenStale(): void
    {
        if ($this->activeRefreshedAt === null || Carbon::parse($this->activeRefreshedAt)->diffInSeconds(now(), true) >= self::RESUME_REFRESH_AFTER_SECONDS) {
            $this->refreshActive();
        }
    }

    private function feedSyncedRecently(): bool
    {
        return $this->syncedAt !== null && Carbon::parse($this->syncedAt)->diffInSeconds(now(), true) < self::RESUME_REFRESH_AFTER_SECONDS;
    }

    /**
     * Reloads the sent-alerts feed, then the current-alerts section. The two are independent: neither
     * one's failure changes the other's state.
     */
    public function refresh(): void
    {
        $this->refreshFeed();
        $this->refreshActive();
    }

    /**
     * Replays reads that did not reach the server, then loads the first page. The page's unread count
     * (less reads still pending) becomes the stored count and the icon badge.
     */
    private function refreshFeed(): void
    {
        $cache = app(LocalCache::class);
        $readState = app(AlertReadState::class);

        try {
            $readState->retryPending();

            $page = app(WxNotifyApi::class)->alerts(limit: (int) config('wxnotify.feed_cache_size'));
            $entries = $readState->applyFeed($page['data'], $page['meta']['unread_count']);

            $cache->replaceFeed($entries);

            $this->alerts = $entries;
            $this->unreadCount = $cache->unreadCount();
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
     * Reloads the section. Offline or on an error it keeps what is shown; a rejected token signs out.
     */
    public function refreshActive(): void
    {
        if (app(TokenStore::class)->token() === null) {
            return;
        }

        try {
            $body = app(WxNotifyApi::class)->activeAlerts();

            app(LocalCache::class)->rememberActiveAlerts($body);

            $this->applyActive($body);
            $this->activeRefreshedAt = now()->toIso8601String();
            $this->activeOffline = false;
            $this->activeError = false;

            if (ActiveAlertsPresenter::hiddenCount($this->activeEventsInEffect(), false) === 0) {
                $this->showAllActive = false;
            }
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->activeOffline = true;
            $this->activeError = false;
        } catch (ApiException) {
            $this->activeOffline = false;
            $this->activeError = true;
        }
    }

    /**
     * @param  array{data: array<int, array<string, mixed>>, meta?: array<string, mixed>}  $body
     */
    private function applyActive(array $body): void
    {
        $this->activeEvents = $body['data'];
        $this->hasPlaces = (bool) ($body['meta']['has_places'] ?? true);
        $this->activeFetchedAt = $body['meta']['generated_at'] ?? null;
        $this->hasActiveCopy = true;
    }

    public function toggleActive(): void
    {
        $this->showAllActive = ! $this->showAllActive;
    }

    /**
     * Opens the event's public product page in the in-app browser; an unknown event, or one with no page, does nothing.
     */
    public function openActive(int $id): void
    {
        $url = collect($this->activeEvents)->firstWhere('id', $id)['view_url'] ?? null;

        if (filled($url)) {
            Browser::inApp($url);
        }
    }

    /**
     * Every event still in effect. An event whose end has passed is never shown, however old the copy
     * (a saved copy read offline, or a screen left open). It compares with the phone's clock, at render time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeEventsInEffect(): array
    {
        return ActiveAlertsPresenter::withoutEnded($this->activeEvents);
    }

    /**
     * The event rows to show: all of them, or the first few until the subscriber expands the list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeRows(): array
    {
        return ActiveAlertsPresenter::visible($this->activeEventsInEffect(), $this->showAllActive);
    }

    public function activeHiddenCount(): int
    {
        return ActiveAlertsPresenter::hiddenCount($this->activeEventsInEffect(), $this->showAllActive);
    }

    /**
     * True when the list is expanded and has more events than the collapsed view shows, so "Show fewer" is offered.
     */
    public function canShowFewerActive(): bool
    {
        return $this->showAllActive && ActiveAlertsPresenter::hiddenCount($this->activeEventsInEffect(), false) > 0;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function activeHeadline(array $event): string
    {
        return ActiveAlertsPresenter::headline($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function activeOverline(array $event): string
    {
        return ActiveAlertsPresenter::overline($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function activePlaces(array $event): string
    {
        return ActiveAlertsPresenter::places($event);
    }

    /**
     * The note above the events: offline, when the shown copy was fetched (or that there is none to show);
     * after a failed refresh with events still on screen, that they could not be refreshed and their age.
     */
    public function activeNote(): ?string
    {
        if ($this->activeOffline) {
            return match (true) {
                ! $this->hasActiveCopy => 'Current alerts need a connection.',
                $this->activeFetchedAt === null => "You're offline.",
                default => ActiveAlertsPresenter::asOf($this->activeFetchedAt, $this->timezone).". You're offline.",
            };
        }

        if ($this->activeError && $this->activeEventsInEffect() !== []) {
            return $this->activeFetchedAt === null
                ? "Couldn't refresh current alerts."
                : "Couldn't refresh current alerts. ".ActiveAlertsPresenter::asOf($this->activeFetchedAt, $this->timezone);
        }

        return null;
    }

    /**
     * The line that replaces the event rows when there are none to show.
     */
    public function activeEmptyLine(): ?string
    {
        if ($this->activeEventsInEffect() !== [] || ($this->activeOffline && ! $this->hasActiveCopy)) {
            return null;
        }

        return match (true) {
            $this->activeError => "Couldn't load current alerts.",
            $this->activeOffline && $this->activeEvents !== [] => $this->endedSavedAlertsLine(),
            ! $this->hasPlaces => "Add a place or turn on location sharing to see what's in effect near you.",
            default => 'Nothing in effect at your places.',
        };
    }

    /**
     * Offline, with a saved copy whose events have all ended: say so rather than that nothing is in effect,
     * which the phone cannot know without a connection.
     */
    private function endedSavedAlertsLine(): string
    {
        return ActiveAlertsPresenter::savedAlertsEnded($this->activeFetchedAt, $this->timezone);
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

            $this->alerts = [...$this->alerts, ...app(AlertReadState::class)->applyFeed($page['data'], null)];
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

    /**
     * Reads the alert, then opens its public product page in the in-app browser; an alert with no product
     * page (a daily summary, say) or a cached row from before the feed carried the link opens its detail.
     */
    public function open(int $id): void
    {
        app(AlertReadState::class)->open($id);
        $this->showAsRead($id);

        $alert = collect($this->alerts)->firstWhere('id', $id);

        if (filled($alert['product_url'] ?? null)) {
            Browser::inApp($alert['product_url']);

            return;
        }

        $this->openDetail($id);
    }

    /**
     * The long-press action: the alert's detail (rule, channels, delivery status), whether or not it
     * has a product page. The detail reads the alert; coming back picks the flag up from the cache.
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
     * Reads every alert ("Mark all as read").
     */
    public function markAllRead(): void
    {
        app(AlertReadState::class)->markAll();

        $this->alerts = array_map(fn (array $alert): array => [...$alert, 'read' => true], $this->alerts);
        $this->unreadCount = app(LocalCache::class)->unreadCount();
    }

    /**
     * True when anything is unread, so "Mark all as read" is offered.
     */
    public function hasUnread(): bool
    {
        return ($this->unreadCount ?? 0) > 0
            || collect($this->alerts)->contains(fn (array $alert): bool => ($alert['read'] ?? true) === false);
    }

    /**
     * Shows one alert read straight away (also a row from a further page, which the cache does not hold).
     */
    private function showAsRead(int $id): void
    {
        $this->alerts = array_map(fn (array $alert): array => $alert['id'] === $id ? [...$alert, 'read' => true] : $alert, $this->alerts);
        $this->unreadCount = app(LocalCache::class)->unreadCount();
    }

    /**
     * Takes the cached read flags and count (a detail opened from here may have read an alert).
     */
    private function applyCachedReadState(): void
    {
        $flags = collect(app(LocalCache::class)->feed())->pluck('read', 'id');

        $this->alerts = array_map(fn (array $alert): array => $flags->has($alert['id']) ? [...$alert, 'read' => $flags->get($alert['id'])] : $alert, $this->alerts);
        $this->unreadCount = app(LocalCache::class)->unreadCount();
    }

    /**
     * The row's supporting text: "Delivery problem" when a send failed, otherwise none.
     *
     * @param  array<string, mixed>  $alert
     */
    public function supporting(array $alert): ?string
    {
        return $alert['has_failure'] ? 'Delivery problem' : null;
    }

    /**
     * The row's trailing badges: a dot in the theme's primary colour while the alert is unread, and a
     * warning triangle when a send failed (the words "Delivery problem" are in the supporting text, so
     * failure is never conveyed by the badge colour alone).
     *
     * @param  array<string, mixed>  $alert
     * @return array<int, array{icon: string, ios: Ios, android: Android, color: string}>
     */
    public function trailingBadges(array $alert): array
    {
        $badges = [];

        if (($alert['read'] ?? true) === false) {
            $badges[] = ['icon' => 'circle', 'ios' => Ios::CircleFill, 'android' => Android::Circle, 'color' => (string) theme('primary', '#2563EB')];
        }

        if ($alert['has_failure']) {
            $badges[] = ['icon' => 'warning', 'ios' => Ios::ExclamationmarkTriangleFill, 'android' => Android::Warning, 'color' => '#EF4444'];
        }

        return $badges;
    }

    public function syncedLabel(): string
    {
        if ($this->syncedAt === null) {
            return '';
        }

        return AlertPresenter::absolute($this->syncedAt, $this->timezone) ?? AlertPresenter::when($this->syncedAt);
    }
}

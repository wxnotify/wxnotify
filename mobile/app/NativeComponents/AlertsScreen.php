<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Illuminate\Support\Carbon;
use Native\Mobile\Edge\NativeComponent;

/**
 * The home screen: the subscriber's recent alerts. Paints from the local cache first, then
 * refreshes; offline it keeps showing what it has, with a banner.
 */
class AlertsScreen extends NativeComponent
{
    use RequiresSignIn;

    private const RESUME_REFRESH_AFTER_SECONDS = 60;

    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    public ?string $nextCursor = null;

    public bool $offline = false;

    public ?string $error = null;

    public ?string $syncedAt = null;

    /** True once a further page has been appended, so coming back to the screen must not reset the list. */
    public bool $pagedBeyondFirst = false;

    /** Guards loadMore(): the list fires end-reached for every row that appears near the bottom. */
    public bool $loadingMore = false;

    public ?string $timezone = null;

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
     * The list's end-reached handler. It fires repeatedly while the last rows are on screen, so it
     * does nothing without a next cursor, while a page is already loading, or when the feed is offline.
     */
    public function loadMore(): void
    {
        if ($this->nextCursor === null || $this->loadingMore) {
            return;
        }

        $this->loadingMore = true;

        try {
            $page = app(WxNotifyApi::class)->alerts($this->nextCursor);

            $this->alerts = [...$this->alerts, ...$page['data']];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->pagedBeyondFirst = true;
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

    public function open(int $id): void
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

    public function syncedLabel(): string
    {
        if ($this->syncedAt === null) {
            return '';
        }

        return AlertPresenter::absolute($this->syncedAt, $this->timezone) ?? AlertPresenter::when($this->syncedAt);
    }
}

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
use Native\Mobile\Edge\NativeComponent;

/**
 * The home screen: the subscriber's recent alerts. Paints from the local cache first, then
 * refreshes; offline it keeps showing what it has, with a banner.
 */
class AlertsScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    public ?string $nextCursor = null;

    public bool $offline = false;

    public ?string $error = null;

    public ?string $syncedAt = null;

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

    public function onResume(): void
    {
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
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load your alerts. Pull down to try again.';
        }
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        try {
            $page = app(WxNotifyApi::class)->alerts($this->nextCursor);

            $this->alerts = [...$this->alerts, ...$page['data']];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->offline = false;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load more alerts. Try again.';
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

    public function syncedLabel(): string
    {
        if ($this->syncedAt === null) {
            return '';
        }

        return AlertPresenter::absolute($this->syncedAt, $this->timezone) ?? AlertPresenter::when($this->syncedAt);
    }
}

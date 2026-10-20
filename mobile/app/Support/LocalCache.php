<?php

namespace App\Support;

use App\Models\CachedAlert;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the app keeps on the phone so it opens instantly and works offline: the latest feed
 * page, any alert detail the subscriber opened, the profile from /me, and when the feed was
 * last refreshed, the read state (each cached alert's flag, the last known unread count, the reads
 * still waiting for the server and the feed's as_of), plus the alert rules, saved locations, notification channels, rule-form options, the map's page, the current watches, warnings and advisories and the recent product searches. Signing out wipes all of it.
 */
class LocalCache
{
    private const SYNCED_AT = 'wxnotify.feed.synced_at';

    private const PROFILE = 'wxnotify.profile';

    private const RULES = 'wxnotify.rules';

    private const LOCATIONS = 'wxnotify.locations';

    private const LOCATIONS_META = 'wxnotify.locations.meta';

    private const OPTIONS = 'wxnotify.rule_options';

    private const CHANNELS = 'wxnotify.channels';

    private const MAP_PAGE = 'wxnotify.map.page';

    private const SEARCHES = 'wxnotify.product_searches';

    private const ACTIVE_ALERTS = 'wxnotify.active_alerts';

    private const UNREAD_COUNT = 'wxnotify.alerts.unread_count';

    private const PENDING_READS = 'wxnotify.alerts.pending_reads';

    private const PENDING_READ_ALL = 'wxnotify.alerts.pending_read_all';

    private const FEED_AS_OF = 'wxnotify.alerts.feed_as_of';

    private const MAX_SEARCHES = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feed(): array
    {
        return CachedAlert::query()
            ->where('in_feed', true)
            ->orderByDesc('sort_at')
            ->orderByDesc('alert_id')
            ->limit((int) config('wxnotify.feed_cache_size'))
            ->get()
            ->map(fn (CachedAlert $alert): array => [...$alert->list, 'read' => $alert->read])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries  as the API returned them (each with its `read` flag; an
     *                                                     entry without one counts as read); the newest are the ones kept
     */
    public function replaceFeed(array $entries): void
    {
        usort($entries, fn (array $a, array $b): int => [Carbon::parse($b['dispatched_at']), $b['id']]
            <=> [Carbon::parse($a['dispatched_at']), $a['id']]);

        DB::transaction(function () use ($entries): void {
            CachedAlert::query()->update(['in_feed' => false]);

            foreach (array_slice($entries, 0, (int) config('wxnotify.feed_cache_size')) as $entry) {
                CachedAlert::query()->updateOrCreate(
                    ['alert_id' => $entry['id']],
                    ['sort_at' => Carbon::parse($entry['dispatched_at']), 'list' => $entry, 'in_feed' => true, 'read' => (bool) ($entry['read'] ?? true)],
                );
            }

            CachedAlert::query()->where('in_feed', false)->whereNull('detail')->delete();
        });

        Cache::forever(self::SYNCED_AT, now()->toIso8601String());
    }

    /**
     * @param  array<string, mixed>  $detail  a detail payload (a superset of the feed entry); its `read` flag, when
     *                                        present, is the server's and replaces the cached one, except that it never
     *                                        turns a read still waiting for the server (or a pending mark all) unread
     */
    public function storeDetail(array $detail): void
    {
        $alert = CachedAlert::query()->firstOrNew(['alert_id' => $detail['id']]);

        if (! $alert->exists) {
            $alert->fill(['sort_at' => Carbon::parse($detail['dispatched_at']), 'list' => $detail, 'in_feed' => false]);
        }

        if (array_key_exists('read', $detail) && ($detail['read'] || ! $this->readIsPending((int) $detail['id'], $detail['dispatched_at'] ?? null))) {
            $alert->read = (bool) $detail['read'];
        }

        $alert->detail = $detail;
        $alert->save();
    }

    /**
     * Marks a cached alert read.
     *
     * @return bool true when the alert was cached and unread until now
     */
    public function markAlertRead(int $id): bool
    {
        return CachedAlert::query()->whereKey($id)->where('read', false)->update(['read' => true]) > 0;
    }

    /**
     * A pending mark all with a cutoff covers only alerts sent at or before it.
     */
    private function readIsPending(int $id, mixed $dispatchedAt): bool
    {
        return ($this->pendingReadAll() && ReadCutoff::covers($this->pendingReadAllCutoff(), $dispatchedAt))
            || in_array($id, $this->pendingReads(), true);
    }

    /**
     * Marks the cached alerts read: all of them, or with a cutoff (see ReadCutoff) only those sent at or before it.
     */
    public function markAllAlertsRead(?string $through = null): void
    {
        $ids = CachedAlert::query()->where('read', false)->get()
            ->filter(fn (CachedAlert $alert): bool => ReadCutoff::covers($through, $alert->list['dispatched_at'] ?? null))
            ->modelKeys();

        CachedAlert::query()->whereKey($ids)->update(['read' => true]);
    }

    /**
     * @return int the cached feed alerts still unread
     */
    public function unreadInFeed(): int
    {
        return count(array_filter($this->feed(), fn (array $alert): bool => $alert['read'] === false));
    }

    /**
     * @return bool|null the cached read flag, or null when this phone has not cached the alert
     */
    public function isCachedRead(int $id): ?bool
    {
        return CachedAlert::query()->find($id)?->read;
    }

    /**
     * @return int|null the unread count the server last reported (adjusted by reads made here), null until known
     */
    public function unreadCount(): ?int
    {
        return Cache::get(self::UNREAD_COUNT);
    }

    public function rememberUnreadCount(int $count): void
    {
        Cache::forever(self::UNREAD_COUNT, max(0, $count));
    }

    /**
     * @return array<int, int> ids read on this phone whose read call has not reached the server yet
     */
    public function pendingReads(): array
    {
        return Cache::get(self::PENDING_READS, []);
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function rememberPendingReads(array $ids): void
    {
        if ($ids === []) {
            Cache::forget(self::PENDING_READS);

            return;
        }

        Cache::forever(self::PENDING_READS, array_values(array_unique($ids)));
    }

    /**
     * True when a "Mark all as read" made on this phone has not reached the server yet.
     */
    public function pendingReadAll(): bool
    {
        return (bool) Cache::get(self::PENDING_READ_ALL, false);
    }

    /**
     * The cutoff of the pending "Mark all as read", replayed as it was made; null when none is pending or it has
     * none (an older backend, or `true` stored by an earlier build), which reads everything.
     */
    public function pendingReadAllCutoff(): ?string
    {
        $value = Cache::get(self::PENDING_READ_ALL);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  string|null  $through  the cutoff the mark all was made with, stored in place of `true`
     */
    public function rememberPendingReadAll(bool $pending = true, ?string $through = null): void
    {
        if ($pending) {
            Cache::forever(self::PENDING_READ_ALL, $through ?? true);

            return;
        }

        Cache::forget(self::PENDING_READ_ALL);
    }

    /**
     * The `meta.as_of` of the last first-page feed load: the cutoff a "Mark all as read" sends. Null until a
     * backend that reports one was loaded.
     */
    public function feedAsOf(): ?string
    {
        return Cache::get(self::FEED_AS_OF);
    }

    public function rememberFeedAsOf(?string $asOf): void
    {
        if ($asOf === null) {
            Cache::forget(self::FEED_AS_OF);

            return;
        }

        Cache::forever(self::FEED_AS_OF, $asOf);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        return CachedAlert::query()->find($id)?->detail;
    }

    public function syncedAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::SYNCED_AT);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    public function rememberProfile(array $user): void
    {
        Cache::forever(self::PROFILE, $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(): ?array
    {
        return Cache::get(self::PROFILE);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function rememberRules(array $rules): void
    {
        Cache::forever(self::RULES, $rules);
    }

    /**
     * @return array<int, array<string, mixed>>|null null until the list was fetched once
     */
    public function rules(): ?array
    {
        return Cache::get(self::RULES);
    }

    /**
     * @param  array<int, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $meta
     */
    public function rememberLocations(array $locations, array $meta): void
    {
        Cache::forever(self::LOCATIONS, $locations);
        Cache::forever(self::LOCATIONS_META, $meta);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function locations(): ?array
    {
        return Cache::get(self::LOCATIONS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function locationsMeta(): ?array
    {
        return Cache::get(self::LOCATIONS_META);
    }

    /**
     * @param  array<int, array<string, mixed>>  $channels
     */
    public function rememberChannels(array $channels): void
    {
        Cache::forever(self::CHANNELS, $channels);
    }

    /**
     * @return array<int, array<string, mixed>>|null null until the list was fetched once
     */
    public function channels(): ?array
    {
        return Cache::get(self::CHANNELS);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function rememberOptions(array $options): void
    {
        Cache::forever(self::OPTIONS, $options);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function options(): ?array
    {
        return Cache::get(self::OPTIONS);
    }

    /**
     * Keeps the map's web page (with its geometry) for the web view, which loads it from the `/map-page` route
     * because a page this size cannot travel through the native element tree. Replaced on each fetch and wiped at sign-out.
     *
     * @return string a version for the page's URL: it changes only when the page does
     */
    public function rememberMapPage(string $html): string
    {
        Cache::forever(self::MAP_PAGE, $html);

        return substr(sha1($html), 0, 16);
    }

    public function mapPage(): ?string
    {
        return Cache::get(self::MAP_PAGE);
    }

    /**
     * The last searches made in the Products tab, newest first. Kept on the phone only.
     *
     * @return array<int, array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}>
     */
    public function recentSearches(): array
    {
        return Cache::get(self::SEARCHES, []);
    }

    /**
     * @param  array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}  $search
     * @param  array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}|null  $replacing  an earlier search this one supersedes (the same search with a filter changed): it is dropped instead of kept beside it
     */
    public function rememberSearch(array $search, ?array $replacing = null): void
    {
        $key = fn (array $entry): string => json_encode([mb_strtolower($entry['q']), $entry['type'], $entry['wfo'], $entry['period']]);

        $others = array_filter($this->recentSearches(), fn (array $entry): bool => $key($entry) !== $key($search) && ($replacing === null || $key($entry) !== $key($replacing)));

        Cache::forever(self::SEARCHES, array_slice([$search, ...array_values($others)], 0, self::MAX_SEARCHES));
    }

    public function forgetSearches(): void
    {
        Cache::forget(self::SEARCHES);
    }

    /**
     * The last "current watches, warnings and advisories" response, for the Alerts screen offline. Replaced on
     * every fetch; the screen drops events that have ended and says when it was fetched.
     *
     * @param  array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}  $body
     */
    public function rememberActiveAlerts(array $body): void
    {
        Cache::forever(self::ACTIVE_ALERTS, $body);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}|null null until it was fetched once
     */
    public function activeAlerts(): ?array
    {
        return Cache::get(self::ACTIVE_ALERTS);
    }

    public function wipe(): void
    {
        CachedAlert::query()->delete();
        Cache::forget(self::SYNCED_AT);
        Cache::forget(self::PROFILE);
        Cache::forget(self::RULES);
        Cache::forget(self::LOCATIONS);
        Cache::forget(self::LOCATIONS_META);
        Cache::forget(self::OPTIONS);
        Cache::forget(self::CHANNELS);
        Cache::forget(self::MAP_PAGE);
        Cache::forget(self::SEARCHES);
        Cache::forget(self::ACTIVE_ALERTS);
        Cache::forget(self::UNREAD_COUNT);
        Cache::forget(self::PENDING_READS);
        Cache::forget(self::PENDING_READ_ALL);
        Cache::forget(self::FEED_AS_OF);
    }
}

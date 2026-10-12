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
 * last refreshed, plus the alert rules, saved locations, notification channels, rule-form options, the map's page, the current watches, warnings and advisories and the recent product searches. Signing out wipes all of it.
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
            ->map(fn (CachedAlert $alert): array => $alert->list)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries  as the API returned them; the newest are the ones kept
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
                    ['sort_at' => Carbon::parse($entry['dispatched_at']), 'list' => $entry, 'in_feed' => true],
                );
            }

            CachedAlert::query()->where('in_feed', false)->whereNull('detail')->delete();
        });

        Cache::forever(self::SYNCED_AT, now()->toIso8601String());
    }

    /**
     * @param  array<string, mixed>  $detail  a detail payload (a superset of the feed entry)
     */
    public function storeDetail(array $detail): void
    {
        $alert = CachedAlert::query()->firstOrNew(['alert_id' => $detail['id']]);

        if (! $alert->exists) {
            $alert->fill(['sort_at' => Carbon::parse($detail['dispatched_at']), 'list' => $detail, 'in_feed' => false]);
        }

        $alert->detail = $detail;
        $alert->save();
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
    }
}

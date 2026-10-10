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
 * last refreshed, plus the alert rules, saved locations and rule-form options. Signing out wipes all of it.
 */
class LocalCache
{
    private const SYNCED_AT = 'wxnotify.feed.synced_at';

    private const PROFILE = 'wxnotify.profile';

    private const RULES = 'wxnotify.rules';

    private const LOCATIONS = 'wxnotify.locations';

    private const LOCATIONS_META = 'wxnotify.locations.meta';

    private const OPTIONS = 'wxnotify.rule_options';

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

    public function wipe(): void
    {
        CachedAlert::query()->delete();
        Cache::forget(self::SYNCED_AT);
        Cache::forget(self::PROFILE);
        Cache::forget(self::RULES);
        Cache::forget(self::LOCATIONS);
        Cache::forget(self::LOCATIONS_META);
        Cache::forget(self::OPTIONS);
    }
}

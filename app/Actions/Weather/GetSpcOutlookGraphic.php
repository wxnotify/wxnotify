<?php

namespace App\Actions\Weather;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One of SPC's outlook graphics (Days 1, 2, 3 and the Days 4-8 overview), fetched at send time so the
 * Full outlook report email can embed it inline. These are SPC's full-size images (1630 px wide, about
 * 370 KB, for Days 1-3; 815 px, about 175 KB, for Days 4-8), shown at 380 px, because the small ones
 * (190 px) would blur at that size.
 *
 * Only a fixed key picks a fixed URL (no other input reaches a URL); the host is fixed; redirects
 * are not followed; and the body is accepted only when status, content type, magic bytes and size
 * all check out. Cached as base64 like GetRadarSnapshot (the cache store may be a text column), and
 * a failure is cached too (as a sentinel), so one bad graphic costs one request per 2 minutes, not
 * one per email. many() fetches the set in one pool, bounding the whole set to one timeout.
 */
class GetSpcOutlookGraphic
{
    private const MAX_BYTES = 1_048_576;

    private const TIMEOUT_SECONDS = 5;

    private const URLS = [
        'day1' => 'https://www.spc.noaa.gov/products/outlook/day1otlk.png',
        'day2' => 'https://www.spc.noaa.gov/products/outlook/day2otlk.png',
        'day3' => 'https://www.spc.noaa.gov/products/outlook/day3otlk.png',
        'day48' => 'https://www.spc.noaa.gov/products/exper/day4-8/day48prob.gif',
    ];

    private const MAGIC = [
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/png' => ["\x89PNG\r\n\x1a\n"],
    ];

    /** Cached in place of a failed graphic so that every email of a run does not ask again. */
    private const FAILED = 'failed';

    /**
     * The fixed SPC address of each graphic, keyed day1, day2, day3 and day48 (the dashboard preview
     * shows them straight from SPC).
     *
     * @return array<string, string>
     */
    public static function urls(): array
    {
        return self::URLS;
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function __invoke(string $key): ?array
    {
        return $this->many([$key])[$key] ?? null;
    }

    /**
     * The graphics for the given keys, fetched together (one pool, so the whole set takes at most one
     * request timeout); a key that is unknown or failed maps to null. Successes and failures are both
     * cached for 2 minutes.
     *
     * @param  list<string>  $keys
     * @return array<string, array{bytes: string, mime: string}|null>
     */
    public function many(array $keys): array
    {
        $results = [];
        $toFetch = [];

        foreach ($keys as $key) {
            $results[$key] = null;

            if (! isset(self::URLS[$key])) {
                continue;
            }

            $cached = Cache::get($this->cacheKey($key));

            if ($cached === null) {
                $toFetch[] = $key;
            } elseif (is_array($cached)) {
                $results[$key] = $this->decode($cached);
            }
        }

        foreach ($this->fetch($toFetch) as $key => $fetched) {
            Cache::put($this->cacheKey($key), $fetched ?? self::FAILED, now()->addMinutes(2));
            $results[$key] = $fetched === null ? null : $this->decode($fetched);
        }

        return $results;
    }

    private function cacheKey(string $key): string
    {
        return "spc-outlook-graphic:{$key}";
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array{bytes: string, mime: string}|null
     */
    private function decode(array $cached): ?array
    {
        $bytes = is_string($cached['base64'] ?? null) ? base64_decode($cached['base64'], true) : false;

        return $bytes === false || ! is_string($cached['mime'] ?? null) ? null : ['bytes' => $bytes, 'mime' => $cached['mime']];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{base64: string, mime: string}|null>
     */
    private function fetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $results = array_fill_keys($keys, null);

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $key) => $pool->as($key)
                    ->withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->get(self::URLS[$key]),
                $keys,
            ));
        } catch (Throwable $e) {
            // A graphic is optional: nothing may escape into the delivery job. Class only, never the message.
            Log::warning('SPC outlook graphic fetch failed with an error', ['exception' => $e::class]);

            return $results;
        }

        foreach ($keys as $key) {
            $results[$key] = $this->accept($responses[$key] ?? null);
        }

        return $results;
    }

    /**
     * @return array{base64: string, mime: string}|null
     */
    private function accept(mixed $response): ?array
    {
        if ($response instanceof ConnectionException) {
            Log::warning('SPC outlook graphic fetch failed to connect');

            return null;
        }

        if ($response instanceof Throwable) {
            Log::warning('SPC outlook graphic fetch failed with an error', ['exception' => $response::class]);

            return null;
        }

        if (! $response instanceof Response) {
            return null;
        }

        if ($response->status() !== 200) {
            Log::warning('SPC outlook graphic fetch failed', ['status' => $response->status()]);

            return null;
        }

        $bytes = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || ! isset(self::MAGIC[$mime])) {
            return null;
        }

        foreach (self::MAGIC[$mime] as $signature) {
            if (str_starts_with($bytes, $signature)) {
                return ['base64' => base64_encode($bytes), 'mime' => $mime];
            }
        }

        return null;
    }
}

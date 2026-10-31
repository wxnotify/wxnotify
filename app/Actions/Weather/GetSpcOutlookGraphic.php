<?php

namespace App\Actions\Weather;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One of SPC's small outlook graphics (Days 1, 2, 3 and the Days 4-8 overview), fetched at send time
 * so the Full outlook report email can embed it inline.
 *
 * Only a fixed key picks a fixed URL (no other input reaches a URL); the host is fixed; redirects
 * are not followed; and the body is accepted only when status, content type, magic bytes and size
 * all check out. Cached as base64 like GetRadarSnapshot: the cache store may be a text column.
 */
class GetSpcOutlookGraphic
{
    private const MAX_BYTES = 1_048_576;

    private const URLS = [
        'day1' => 'https://www.spc.noaa.gov/products/outlook/day1otlk_sm.png',
        'day2' => 'https://www.spc.noaa.gov/products/outlook/day2otlk_sm.png',
        'day3' => 'https://www.spc.noaa.gov/products/outlook/day3otlk_sm.png',
        'day48' => 'https://www.spc.noaa.gov/products/exper/day4-8/day48prob_small.gif',
    ];

    private const MAGIC = [
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/png' => ["\x89PNG\r\n\x1a\n"],
    ];

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function __invoke(string $key): ?array
    {
        $url = self::URLS[$key] ?? null;

        if ($url === null) {
            return null;
        }

        $cached = Cache::remember("spc-outlook-graphic:{$key}", now()->addMinutes(2), fn (): ?array => $this->fetch($url));

        if ($cached === null) {
            return null;
        }

        $bytes = base64_decode($cached['base64'], true);

        return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $cached['mime']];
    }

    /**
     * @return array{base64: string, mime: string}|null
     */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException) {
            Log::warning('SPC outlook graphic fetch failed to connect');

            return null;
        } catch (Throwable $e) {
            // A graphic is optional: nothing may escape into the delivery job. Class only, never the message.
            Log::warning('SPC outlook graphic fetch failed with an error', ['exception' => $e::class]);

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

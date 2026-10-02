<?php

namespace App\Actions\Weather;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The latest still radar frame for a radar site, fetched at send time so the
 * Full Daily Summary email can embed it inline as a true snapshot.
 *
 * The site code comes from an external response and goes into a URL, so it is
 * validated before any request; the host is fixed; redirects are not followed;
 * and the body is accepted only when status, content type, magic bytes and
 * size all check out (nothing else is ever embedded in an email).
 *
 * Cached as base64: the cache store may be a text column, which cannot hold
 * arbitrary binary.
 */
class GetRadarSnapshot
{
    private const MAX_BYTES = 1_048_576;

    private const MAGIC = [
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/png' => ["\x89PNG\r\n\x1a\n"],
    ];

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function __invoke(string $site): ?array
    {
        if (preg_match('/^[A-Z0-9]{3,4}$/', $site) !== 1) {
            return null;
        }

        $cached = Cache::remember("nws-radar:{$site}", now()->addMinutes(2), fn (): ?array => $this->fetch($site));

        if ($cached === null) {
            return null;
        }

        $bytes = base64_decode($cached['base64'], true);

        return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $cached['mime']];
    }

    /**
     * @return array{base64: string, mime: string}|null
     */
    private function fetch(string $site): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get("https://radar.weather.gov/ridge/standard/{$site}_0.gif");
        } catch (ConnectionException) {
            Log::warning('NWS radar snapshot fetch failed to connect');

            return null;
        }

        if ($response->status() !== 200) {
            Log::warning('NWS radar snapshot fetch failed', ['status' => $response->status()]);

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

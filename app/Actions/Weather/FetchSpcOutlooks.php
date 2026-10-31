<?php

namespace App\Actions\Weather;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SPC convective outlooks for Days 1-8 as plain arrays, fetched at send time.
 *
 * Every URL is built here from a fixed host and fixed file names (no outside input reaches
 * one). A 200 whose body is not a GeoJSON FeatureCollection (an HTML error page, invalid
 * JSON) counts as that file being unavailable, never as "no storms"; an empty feature list
 * is a valid quiet day. Only each feature's LABEL and geometry are kept.
 *
 * A result in which any file failed (a whole day, or one layer of it) is not cached, so the next
 * call asks SPC again.
 */
class FetchSpcOutlooks
{
    private const BASE = 'https://www.spc.noaa.gov/products/';

    /**
     * @return array<string, array{issued_at: ?string, last_modified: ?string, layers: array<string, list<array{label: string, geometry: array<string, mixed>}>>}|null>
     */
    public function __invoke(): array
    {
        $cached = Cache::get('spc-outlooks');

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch();

        if ($this->isComplete($result)) {
            Cache::put('spc-outlooks', $result, now()->addMinutes(2));
        }

        return $result;
    }

    /**
     * @return array<string, array{issued_at: ?string, last_modified: ?string, layers: array<string, list<array{label: string, geometry: array<string, mixed>}>>}|null>
     */
    private function fetch(): array
    {
        $files = $this->files();

        try {
            $responses = Http::pool(function (Pool $pool) use ($files): array {
                $requests = [];

                foreach ($files as $key => $path) {
                    $requests[] = $this->configure($pool->as($key))->get(self::BASE.$path);
                }

                return $requests;
            });
        } catch (Throwable $e) {
            Log::warning('SPC outlook fetch failed', ['exception' => $e::class]);

            $responses = [];
        }

        $result = [];

        for ($day = 1; $day <= 8; $day++) {
            $result["day_{$day}"] = $this->day($day, $responses);
        }

        return $result;
    }

    /**
     * Whether every requested file parsed (an empty layer counts; a failed one does not).
     *
     * @param  array<string, mixed>  $result
     */
    private function isComplete(array $result): bool
    {
        foreach (array_keys($this->files()) as $key) {
            [$day, $layer] = explode(':', $key);

            if (! array_key_exists($layer, $result["day_{$day}"]['layers'] ?? [])) {
                return false;
            }
        }

        return true;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
            ->timeout(8)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @return array<string, string> request key "day:layer" => path under the SPC products directory
     */
    private function files(): array
    {
        $files = [];

        foreach ([1, 2] as $day) {
            foreach (['cat', 'torn', 'hail', 'wind'] as $layer) {
                $files["{$day}:{$layer}"] = "outlook/day{$day}otlk_{$layer}.nolyr.geojson";
            }
        }

        $files['3:cat'] = 'outlook/day3otlk_cat.nolyr.geojson';
        $files['3:prob'] = 'outlook/day3otlk_prob.nolyr.geojson';

        foreach ([4, 5, 6, 7, 8] as $day) {
            $files["{$day}:prob"] = "exper/day4-8/day{$day}prob.nolyr.geojson";
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array{issued_at: ?string, last_modified: ?string, layers: array<string, list<array{label: string, geometry: array<string, mixed>}>>}|null
     */
    private function day(int $day, array $responses): ?array
    {
        $layers = [];
        $features = [];

        foreach (['cat', 'torn', 'hail', 'wind', 'prob'] as $layer) {
            $response = $responses["{$day}:{$layer}"] ?? null;
            $parsed = $response instanceof Response ? $this->parse($response, "{$day}:{$layer}") : null;

            if ($parsed === null) {
                continue;
            }

            $features[$layer] = $parsed['raw'];
            $layers[$layer] = $parsed['entries'];
        }

        if ($layers === []) {
            return null;
        }

        $primary = $day <= 3 ? 'cat' : 'prob';
        $issueLayer = $day === 3 ? 'prob' : $primary;
        $lastModified = $responses["{$day}:{$primary}"] ?? null;
        $lastModified = $lastModified instanceof Response && isset($layers[$primary])
            ? ($lastModified->header('Last-Modified') ?: null)
            : null;

        return [
            'issued_at' => $this->issuedAt($features[$issueLayer] ?? []),
            'last_modified' => $lastModified,
            'layers' => $layers,
        ];
    }

    /**
     * @return array{raw: list<mixed>, entries: list<array{label: string, geometry: array<string, mixed>}>}|null
     */
    private function parse(Response $response, string $file): ?array
    {
        if ($response->status() !== 200) {
            Log::warning('SPC outlook file unavailable', ['file' => $file, 'status' => $response->status()]);

            return null;
        }

        $data = json_decode($response->body(), true);

        if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null)) {
            Log::warning('SPC outlook file was not a feature collection', ['file' => $file]);

            return null;
        }

        $entries = [];

        foreach ($data['features'] as $feature) {
            $label = is_array($feature) ? ($feature['properties']['LABEL'] ?? null) : null;
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;

            if (! is_string($label) || ! is_array($geometry) || str_starts_with($label, 'Less Than')) {
                continue;
            }

            $entries[] = ['label' => $label, 'geometry' => $geometry];
        }

        return ['raw' => array_values($data['features']), 'entries' => $entries];
    }

    /**
     * @param  list<mixed>  $features
     */
    private function issuedAt(array $features): ?string
    {
        $first = $features[0] ?? null;
        $issued = is_array($first) ? ($first['properties']['ISSUE_ISO'] ?? null) : null;

        return is_string($issued) && $issued !== '' ? $issued : null;
    }
}

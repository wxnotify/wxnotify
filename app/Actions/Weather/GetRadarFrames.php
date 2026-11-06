<?php

namespace App\Actions\Weather;

use App\Support\NexradSites;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The frame times NOAA's WMS has for a radar layer, read from its
 * GetCapabilities document. The browser loads the tiles itself; the server
 * never fetches an image here.
 *
 * The host is fixed, redirects are not followed, a site code is checked
 * against the known site list before it reaches a URL, and the XML is parsed
 * without entities or network access. Anything wrong is "unavailable", never
 * "no rain", and nothing throws.
 */
class GetRadarFrames
{
    private const HOST_PREFIX = 'https://opengeo.ncep.noaa.gov/';

    private const MAX_TIMES = 120;

    private const SUCCESS_SECONDS = 60;

    private const FAILURE_SECONDS = 30;

    /**
     * @return array{layer: string, status: 'ok'|'unavailable', wms_url: ?string, wms_layer: ?string, times: list<string>}
     */
    public function __invoke(string $layer, ?string $site = null): array
    {
        $target = $this->target($layer, $site);

        if ($target === null) {
            return $this->unavailable($layer);
        }

        $key = 'radar-frames:'.$layer.':'.($site === null ? '-' : strtolower($site));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $times = $this->fetchTimes($target['wms_url'], $target['wms_layer']);
        $result = $times === []
            ? $this->unavailable($layer)
            : ['layer' => $layer, 'status' => 'ok', 'wms_url' => $target['wms_url'], 'wms_layer' => $target['wms_layer'], 'times' => $times];

        Cache::put($key, $result, $times === [] ? self::FAILURE_SECONDS : self::SUCCESS_SECONDS);

        return $result;
    }

    /**
     * @return array{wms_url: string, wms_layer: string}|null
     */
    private function target(string $layer, ?string $site): ?array
    {
        if ($layer === 'composite') {
            $url = (string) config('wxnotify.map.radar.wms_url');
            $wmsLayer = (string) config('wxnotify.map.radar.layer');

            return str_starts_with($url, self::HOST_PREFIX) && $wmsLayer !== '' ? ['wms_url' => $url, 'wms_layer' => $wmsLayer] : null;
        }

        $layerKey = match ($layer) {
            'site_reflectivity' => 'site_reflectivity_layer',
            'site_velocity' => 'site_velocity_layer',
            default => null,
        };

        if ($layerKey === null || $site === null || ! NexradSites::exists($site)) {
            return null;
        }

        $site = strtolower($site);

        return [
            'wms_url' => str_replace('{site}', $site, (string) config('wxnotify.map.radar.site_wms_url')),
            'wms_layer' => str_replace('{site}', $site, (string) config("wxnotify.map.radar.{$layerKey}")),
        ];
    }

    /**
     * @return list<string>
     */
    private function fetchTimes(string $wmsUrl, string $wmsLayer): array
    {
        try {
            $response = Http::timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($wmsUrl, ['service' => 'WMS', 'version' => '1.3.0', 'request' => 'GetCapabilities']);
        } catch (ConnectionException) {
            Log::warning('Radar capabilities fetch failed to connect');

            return [];
        } catch (Throwable $e) {
            Log::warning('Radar capabilities fetch failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->status() !== 200) {
            Log::warning('Radar capabilities fetch failed', ['status' => $response->status()]);

            return [];
        }

        return $this->parseTimes($response->body(), $wmsLayer);
    }

    /**
     * @return list<string>
     */
    private function parseTimes(string $body, string $wmsLayer): array
    {
        if ($body === '' || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return [];
            }

            $nodes = (new DOMXPath($document))->query(
                "//*[local-name()='Layer'][*[local-name()='Name'][normalize-space(.)=".$this->xpathLiteral($wmsLayer)."]]/*[local-name()='Dimension'][@name='time']"
            );

            if ($nodes === false || $nodes->length === 0) {
                return [];
            }

            $times = [];

            foreach (explode(',', (string) $nodes->item(0)?->textContent) as $time) {
                $time = trim($time);

                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z\z/', $time) === 1) {
                    $times[] = $time;
                }
            }

            $times = array_values(array_unique($times));
            sort($times);

            return array_slice($times, -self::MAX_TIMES);
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xpathLiteral(string $value): string
    {
        return "'".str_replace("'", '', $value)."'";
    }

    /**
     * @return array{layer: string, status: 'unavailable', wms_url: null, wms_layer: null, times: list<string>}
     */
    private function unavailable(string $layer): array
    {
        return ['layer' => $layer, 'status' => 'unavailable', 'wms_url' => null, 'wms_layer' => null, 'times' => []];
    }
}

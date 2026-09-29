<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePageScraper;
use App\Support\Shapefiles\ZoneCountyCorrelationPageScraper;
use Closure;
use Illuminate\Support\Facades\Http;

class ScanShapefileLayer
{
    /**
     * $onStep, when given, is called with a short narration of each step —
     * including the three early-return cases (HTTP failure, no matching
     * group, no versions in the group) that otherwise return null exactly
     * like "nothing changed" does, with nothing in the caller able to tell
     * the difference. See App\Console\Commands\ScanShapefileLayers, whose
     * -v flag is what actually supplies this.
     */
    public function __invoke(ShapefileLayer $layer, ?Closure $onStep = null): ?ShapefileLayerVersion
    {
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($layer->source_url);

        if (! $response->successful()) {
            $this->report($onStep, "Failed to fetch {$layer->source_url} (HTTP {$response->status()})");

            return null;
        }

        $this->report($onStep, "Fetched {$layer->source_url}");

        $groups = $definition->format === 'correlation'
            ? (new ZoneCountyCorrelationPageScraper)->scan($response->body())
            : (new ShapefilePageScraper)->scan($response->body());

        $group = $definition->groupMatchText === null
            ? ($groups[0] ?? null)
            : collect($groups)->first(fn (array $g): bool => str_contains($g['description'], $definition->groupMatchText));

        if ($group === null || $group['versions'] === []) {
            $this->report($onStep, 'No matching version group found on the page for this layer');

            return null;
        }

        $latest = collect($group['versions'])->sortByDesc('effective_at')->first();
        $this->report($onStep, "Latest available version: {$latest['filename']} (effective {$latest['effective_at']})");

        $identityChanged = $definition->format === 'correlation'
            ? $latest['filename'] !== $layer->last_seen_filename
            : $latest['checksum'] !== $layer->last_seen_checksum;

        if (! $identityChanged) {
            $this->report($onStep, 'No change since the last scan');

            return null;
        }

        $this->report($onStep, 'New version detected — staging it');

        $version = $layer->versions()->create([
            'filename' => $latest['filename'],
            'checksum' => $latest['checksum'],
            'source_url' => $latest['url'],
            'effective_at' => $latest['effective_at'],
            'status' => ShapefileVersionStatus::Staged,
            'record_count' => $latest['record_count'],
        ]);

        $layer->update([
            'last_seen_filename' => $latest['filename'],
            'last_seen_checksum' => $latest['checksum'],
            'last_seen_effective_at' => $latest['effective_at'],
        ]);

        return $version;
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }

    private function report(?Closure $onStep, string $message): void
    {
        $onStep?->__invoke($message);
    }
}

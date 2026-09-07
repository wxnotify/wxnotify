<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePageScraper;
use App\Support\Shapefiles\ZoneCountyCorrelationPageScraper;
use Illuminate\Support\Facades\Http;

class ScanShapefileLayer
{
    public function __invoke(ShapefileLayer $layer): ?ShapefileLayerVersion
    {
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($layer->source_url);

        if (! $response->successful()) {
            return null;
        }

        $groups = $definition->format === 'correlation'
            ? (new ZoneCountyCorrelationPageScraper)->scan($response->body())
            : (new ShapefilePageScraper)->scan($response->body());

        $group = $definition->groupMatchText === null
            ? ($groups[0] ?? null)
            : collect($groups)->first(fn (array $g): bool => str_contains($g['description'], $definition->groupMatchText));

        if ($group === null || $group['versions'] === []) {
            return null;
        }

        $latest = collect($group['versions'])->sortByDesc('effective_at')->first();

        $identityChanged = $definition->format === 'correlation'
            ? $latest['filename'] !== $layer->last_seen_filename
            : $latest['checksum'] !== $layer->last_seen_checksum;

        if (! $identityChanged) {
            return null;
        }

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
}

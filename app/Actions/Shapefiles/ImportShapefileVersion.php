<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePolygonAssembler;
use App\Support\Shapefiles\ZoneCountyCorrelationParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;
use ZipArchive;

class ImportShapefileVersion
{
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        if ($definition->isShapefile()) {
            $this->importShapefile($version, $definition);
        } else {
            $this->importCorrelation($version, $definition);
        }
    }

    private function importShapefile(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $tempDir = storage_path('app/shapefile-imports/'.$version->id);
        File::ensureDirectoryExists($tempDir);

        try {
            $zipPath = $tempDir.'/download.zip';
            Http::withHeaders(['User-Agent' => $this->userAgent()])->sink($zipPath)->get($version->source_url);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $zip->extractTo($tempDir);
            $zip->close();

            $shpFiles = File::glob($tempDir.'/*.shp') ?: File::glob($tempDir.'/*.SHP');
            $basePath = substr($shpFiles[0], 0, -4);

            $shp = new ShapeFile(ShapeType::Polygon);
            $shp->loadFromFile($basePath.'.*');

            DB::transaction(function () use ($shp, $definition): void {
                DB::table($definition->stagingTable)->delete();

                foreach ($shp->records as $record) {
                    $this->insertShapefileRecord($record, $definition);
                }
            });
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    private function insertShapefileRecord(ShapeRecord $record, ShapefileLayerDefinition $definition): void
    {
        $attributes = [];

        foreach ($definition->attributeMap as $sourceField => $column) {
            $attributes[$column] = trim((string) ($record->dbfData[$sourceField] ?? ''));
        }

        if ($definition->zoneType !== null) {
            $attributes['zone_type'] = $definition->zoneType;
        }

        $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt($record->shpData['parts'] ?? []);

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s, boundary, created_at, updated_at) VALUES (%s, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
            $definition->stagingTable,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        DB::insert($sql, [...array_values($attributes), $wkt]);
    }

    private function importCorrelation(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($version->source_url);
        $rows = (new ZoneCountyCorrelationParser)->parse($response->body());

        DB::transaction(function () use ($rows, $definition): void {
            DB::table($definition->stagingTable)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($definition->stagingTable)->insert(array_map(
                    fn (array $row): array => [...$row, 'created_at' => now(), 'updated_at' => now()],
                    $chunk,
                ));
            }
        });
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}

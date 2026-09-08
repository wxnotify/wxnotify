<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShapefileLayerGeoJsonController extends Controller
{
    public function __invoke(ShapefileLayer $shapefileLayer): JsonResponse
    {
        abort_unless(auth()->user()?->is_staff, 403);

        $definition = ShapefileLayerDefinitions::forKey($shapefileLayer->key);

        if (! $definition->isShapefile()) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $columns = array_values(array_unique($definition->attributeMap));

        $rows = DB::table($definition->liveTable)
            ->select([...$columns, DB::raw('ST_AsGeoJSON(boundary) as geometry_json')])
            ->get();

        $features = $rows->map(function (object $row) use ($columns): array {
            $properties = [];

            foreach ($columns as $column) {
                $properties[$column] = $row->{$column} ?? null;
            }

            return [
                'type' => 'Feature',
                'geometry' => json_decode((string) $row->geometry_json, true),
                'properties' => $properties,
            ];
        })->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}

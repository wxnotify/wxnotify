<?php

namespace App\Actions\Admin\FactoryReset;

use Illuminate\Support\Facades\DB;

class WipePipelineData
{
    /**
     * @return array{ingested_products: int, ingested_product_segments: int, active_wwas: int, active_wwa_areas: int, delivery_logs: int}
     */
    public function __invoke(): array
    {
        $counts = [
            'ingested_products' => DB::table('ingested_products')->count(),
            'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
            'active_wwas' => DB::table('active_wwas')->count(),
            'active_wwa_areas' => DB::table('active_wwa_areas')->count(),
            'delivery_logs' => DB::table('delivery_logs')->count(),
        ];

        DB::statement('TRUNCATE TABLE ingested_products, ingested_product_segments, delivery_log_segment, active_wwas, active_wwa_areas, delivery_logs RESTART IDENTITY');

        return $counts;
    }
}

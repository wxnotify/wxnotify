<?php

namespace App\Actions\Admin\FactoryReset;

use Illuminate\Support\Facades\DB;

class WipePipelineData
{
    /**
     * @return array{ingested_products: int, active_wwas: int, delivery_logs: int}
     */
    public function __invoke(): array
    {
        $counts = [
            'ingested_products' => DB::table('ingested_products')->count(),
            'active_wwas' => DB::table('active_wwas')->count(),
            'delivery_logs' => DB::table('delivery_logs')->count(),
        ];

        DB::statement('TRUNCATE TABLE ingested_products, active_wwas, delivery_logs RESTART IDENTITY');

        return $counts;
    }
}

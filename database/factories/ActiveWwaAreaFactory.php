<?php

namespace Database\Factories;

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ActiveWwaArea>
 */
class ActiveWwaAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'active_wwa_id' => ActiveWwa::factory(),
            'ugc_code' => 'FLZ206',
            'status' => 'Active',
            'effective' => now(),
            'expires' => now()->addHour(),
            'applied_at' => now(),
        ];
    }

    /**
     * Sets the zone's polygon from WKT (SRID 4269), e.g. 'POLYGON((-87 30, -87 31, -86 31, -86 30, -87 30))'.
     */
    public function withPolygon(string $wkt): static
    {
        return $this->afterCreating(function (ActiveWwaArea $area) use ($wkt): void {
            DB::statement('UPDATE active_wwa_areas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $area->id]);
        });
    }
}

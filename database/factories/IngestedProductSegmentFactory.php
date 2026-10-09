<?php

namespace Database\Factories;

use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestedProductSegment>
 */
class IngestedProductSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ingested_product_id' => IngestedProduct::factory(),
            'position' => 0,
            'vtec_office' => 'KMOB',
            'vtec_phenomenon' => 'HU',
            'vtec_significance' => 'A',
            'vtec_event_number' => '1009',
            'vtec_year' => 2026,
            'vtec_action' => 'NEW',
            'ugc_codes' => ['FLZ206'],
        ];
    }

    /**
     * A real catalog event ("HU.A"): sets the VTEC phenomenon and significance and links the catalog product.
     */
    public function forCatalogCode(string $code): static
    {
        return $this->state(fn (): array => [
            'vtec_phenomenon' => substr($code, 0, 2),
            'vtec_significance' => substr($code, 3, 1),
            'product_id' => Product::where('code', $code)->value('id'),
        ]);
    }
}

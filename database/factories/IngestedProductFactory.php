<?php

namespace Database\Factories;

use App\Models\IngestedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestedProduct>
 */
class IngestedProductFactory extends Factory
{
    public function definition(): array
    {
        $cccc = strtolower(fake()->lexify('????'));
        $ttaaii = strtolower(fake()->lexify('??????'));
        $awipsid = strtolower(fake()->lexify('??????'));
        $issued = fake()->dateTimeBetween('-1 week', 'now');
        $id = (string) fake()->unique()->numberBetween(1, 99999);
        $ddhhmm = $issued->format('dHi');

        return [
            'cccc' => $cccc,
            'ttaaii' => $ttaaii,
            'awipsid' => $awipsid,
            'nwws_id' => $id,
            'wmo_ddhhmm' => $ddhhmm,
            'text' => fake()->paragraphs(3, true),
            'file_path' => "{$cccc}/{$cccc}_{$ttaaii}-{$awipsid}.{$ddhhmm}_{$id}.txt",
            'received_at' => $issued,
        ];
    }
}

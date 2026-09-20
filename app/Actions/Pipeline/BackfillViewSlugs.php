<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;

class BackfillViewSlugs
{
    public function __construct(private readonly GenerateViewSlug $generateViewSlug) {}

    public function __invoke(): int
    {
        $updated = 0;

        IngestedProduct::query()
            ->whereNull('view_slug')
            ->chunkById(500, function ($products) use (&$updated): void {
                foreach ($products as $product) {
                    $product->forceFill(['view_slug' => ($this->generateViewSlug)($product)])->saveQuietly();
                    $updated++;
                }
            });

        return $updated;
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One archived product in a search. Public fields only: never the bulletin text, zones or polygons.
 *
 * @property IngestedProduct $resource
 */
class ProductSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $issuedAt = app(GenerateViewSlug::class)->issuedAt($product) ?? $product->received_at;

        return [
            'id' => $product->id,
            'title' => $product->displayTitle(),
            'office' => filled($product->cccc) ? strtoupper($product->cccc) : null,
            'issued_at' => $issuedAt->toIso8601String(),
            'source' => $product->source,
            'product_id' => $product->product_id,
            'type_label' => $product->product?->category?->label(),
            'view_url' => $product->viewUrl(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Products\BuildReadingText;
use App\Actions\Products\GetProductDetails;
use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product's page content for the app's Product screen: the same title, headline, text and detail lines as the
 * public View Product page. `text`, `reading_text` and `has_original` come from BuildReadingText, so a CAP alert is
 * never sent as XML.
 *
 * @property IngestedProduct $resource
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'title' => $product->displayTitle(),
            'headline' => BuildReadingText::readableHeadline($product->headline),
            ...app(BuildReadingText::class)($product),
            'is_test' => (bool) $product->is_test,
            'view_url' => $product->viewUrl(),
            'details' => collect(app(GetProductDetails::class)($product))
                ->map(fn (string $value, string $label): array => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
        ];
    }
}

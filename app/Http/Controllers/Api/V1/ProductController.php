<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Products\ListProductTypes;
use App\Actions\Products\SearchProducts;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProductsRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\ProductSearchResultResource;
use App\Models\IngestedProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(SearchProductsRequest $request, SearchProducts $search): AnonymousResourceCollection
    {
        $page = $search(
            $request->safe()->only(['q', 'type', 'wfo', 'period']),
            (int) $request->validated('limit', 25),
            $request->validated('before'),
        );

        return ProductSearchResultResource::collection($page['products'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor']]]);
    }

    public function types(ListProductTypes $types): JsonResponse
    {
        return response()->json(['data' => $types()]);
    }

    public function show(string $slug): ProductResource
    {
        $product = IngestedProduct::with('product')->where('view_slug', $slug)->first();

        if ($product === null || ($product->is_test && app()->isProduction())) {
            abort(404);
        }

        return new ProductResource($product);
    }
}

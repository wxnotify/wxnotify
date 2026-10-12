<?php

namespace App\Actions\Products;

use App\Enums\ProductCategory;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The catalog products a subscriber can filter the archive search by, grouped by category in the dashboard's
 * section order. Retired products stay (archived rows still point at them); drafts do not.
 */
class ListProductTypes
{
    /**
     * @return list<array{category: string, label: string, products: list<array{id: int, name: string}>}>
     */
    public function __invoke(): array
    {
        $byCategory = Product::query()
            ->whereIn('status', ['active', 'retired'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->groupBy(fn (Product $product): string => $product->category?->value ?? 'other');

        $groups = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $byCategory->has($category->value))
            ->map(fn (ProductCategory $category): array => $this->group($category->value, $category->label(), $byCategory->get($category->value)));

        if ($byCategory->has('other')) {
            $groups->push($this->group('other', 'Other', $byCategory->get('other')));
        }

        return $groups->values()->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{category: string, label: string, products: list<array{id: int, name: string}>}
     */
    private function group(string $category, string $label, $products): array
    {
        return [
            'category' => $category,
            'label' => $label,
            'products' => $products->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])->values()->all(),
        ];
    }
}

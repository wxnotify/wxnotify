<?php

namespace App\Support;

use App\Enums\ProductCategory;
use App\Models\Product;

/**
 * Converts between the flat `alert_rules.risk_levels` column and the per-category
 * state the SPC & WPC alert form keeps (`risk_levels.{category}`, one checklist per
 * category section whose products offer risk levels). Which levels belong to which
 * category is read from the products' own `risk_level_options`, never hardcoded.
 */
final class SpcWpcRiskLevels
{
    /**
     * Each category that has a product with risk levels, mapped to the union of those
     * products' options (in option order). Categories come in `ProductCategory` order,
     * matching the order of the form's sections.
     *
     * @return array<string, list<string>>
     */
    public static function optionsByCategory(): array
    {
        $optionsByCategory = Product::query()
            ->where('family', 'spc_wpc')
            ->whereNotNull('risk_level_options')
            ->orderBy('sort_order')
            ->get(['category', 'risk_level_options'])
            ->filter(fn (Product $product): bool => $product->category !== null)
            ->groupBy(fn (Product $product): string => $product->category->value)
            ->map(fn ($products): array => $products->pluck('risk_level_options')->flatten()->unique()->values()->all());

        $ordered = [];

        foreach (ProductCategory::cases() as $category) {
            if ($optionsByCategory->has($category->value)) {
                $ordered[$category->value] = $optionsByCategory->get($category->value);
            }
        }

        return $ordered;
    }

    /**
     * The form state for a stored flat list: every category that offers risk levels,
     * with the stored levels it offers (in option order). Levels no category offers drop.
     *
     * @param  list<string>|null  $flat
     * @return array<string, list<string>>
     */
    public static function split(?array $flat): array
    {
        $flat ??= [];

        return collect(self::optionsByCategory())
            ->map(fn (array $options): array => array_values(array_filter($options, fn (string $level): bool => in_array($level, $flat, true))))
            ->all();
    }

    /**
     * The flat list to store for per-category form state; anything that is not a level
     * name (null, booleans from a broken binding) is ignored.
     *
     * @return list<string>
     */
    public static function flatten(mixed $byCategory): array
    {
        return collect($byCategory)->flatten()->filter(fn (mixed $level): bool => is_string($level) && $level !== '')->unique()->values()->all();
    }
}

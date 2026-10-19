<?php

namespace App\Support;

use App\Enums\ProductCategory;
use App\Models\AlertRule;
use App\Models\Product;

/**
 * Converts between the risk levels an SPC & WPC rule stores per product (`alert_rule_product.risk_levels`)
 * and the per-category state the alert form keeps (`risk_levels.{category}`, one checklist per category
 * section whose products offer risk levels). Which levels a product or category offers is read from the
 * products' own `risk_level_options`, never hardcoded.
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
     * The form state for a stored rule: every category that offers risk levels, ticked with the levels
     * the rule selected for its own watched products of that category (AlertRule::riskLevelsFor()), in
     * option order. A category whose products the rule does not watch starts empty.
     *
     * @return array<string, list<string>>
     */
    public static function forForm(AlertRule $rule): array
    {
        $selectedByCategory = $rule->products
            ->filter(fn (Product $product): bool => $product->category !== null)
            ->groupBy(fn (Product $product): string => $product->category->value)
            ->map(fn ($products): array => $products->flatMap(fn (Product $product): array => $rule->riskLevelsFor($product->id))->all());

        return collect(self::optionsByCategory())
            ->map(fn (array $options, string $category): array => array_values(array_filter(
                $options,
                fn (string $level): bool => in_array($level, $selectedByCategory->get($category, []), true),
            )))
            ->all();
    }

    /**
     * The levels to store for one product: the given selection limited to the levels the product offers,
     * in its option order (an empty list means every level).
     *
     * @param  list<string>  $levels
     * @return list<string>
     */
    public static function forProduct(Product $product, array $levels): array
    {
        return array_values(array_filter($product->risk_level_options ?? [], fn (string $level): bool => in_array($level, $levels, true)));
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

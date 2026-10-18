<?php

namespace App\Support;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Models\Product;
use App\Models\User;
use InvalidArgumentException;

/**
 * Which matching modes a product may use inside an alert rule, and the row a
 * subscriber starts from when they switch a product on.
 *
 * Only the Weather Alerts and SPC & WPC categories match per product.
 */
final class ProductMatching
{
    /**
     * The modes the product table offers as options for this product. "By product polygon" is offered only for a
     * product that has a polygon (`has_polygon`); a rule that already stores it for another product keeps it
     * (acceptedModes() still allows it, and the table shows a stored mode as stored).
     *
     * @return list<MatchingMode>
     */
    public static function offeredModes(AlertCategory $category, Product $product): array
    {
        return match ($category) {
            AlertCategory::WeatherAlerts => array_values(array_filter(
                self::weatherAlertModes(),
                fn (MatchingMode $mode): bool => $mode !== MatchingMode::Polygon || $product->has_polygon,
            )),
            AlertCategory::SpcWpc => match (true) {
                self::hasRiskLevels($product) => [MatchingMode::Nationwide, MatchingMode::RiskArea],
                (bool) $product->has_polygon => [MatchingMode::Nationwide, MatchingMode::Polygon],
                default => [MatchingMode::Nationwide],
            },
            default => throw self::unsupported($category),
        };
    }

    /**
     * The modes validation accepts for any product of the category.
     *
     * @return list<MatchingMode>
     */
    public static function acceptedModes(AlertCategory $category): array
    {
        return match ($category) {
            AlertCategory::WeatherAlerts => self::weatherAlertModes(),
            AlertCategory::SpcWpc => [MatchingMode::Nationwide, MatchingMode::Polygon, MatchingMode::RiskArea],
            default => throw self::unsupported($category),
        };
    }

    public static function defaultMode(AlertCategory $category, Product $product): MatchingMode
    {
        return match ($category) {
            AlertCategory::WeatherAlerts => $product->has_polygon ? MatchingMode::Polygon : MatchingMode::Location,
            AlertCategory::SpcWpc => match (true) {
                self::hasRiskLevels($product) => MatchingMode::RiskArea,
                (bool) $product->has_polygon => MatchingMode::Polygon,
                default => MatchingMode::Nationwide,
            },
            default => throw self::unsupported($category),
        };
    }

    /**
     * A switched-off table row at the product's default mode. Location-based
     * modes preselect the subscriber's first saved location (lowest id);
     * pass $firstLocationId to skip the lookup when building many rows.
     *
     * @return array{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
     */
    public static function defaultRow(User $user, AlertCategory $category, Product $product, ?int $firstLocationId = null): array
    {
        $mode = self::defaultMode($category, $product);

        $locationIds = [];

        if (self::isLocationBased($mode)) {
            $firstLocationId ??= $user->locations()->orderBy('id')->value('id');

            if ($firstLocationId !== null) {
                $locationIds = [(int) $firstLocationId];
            }
        }

        return [
            'product_id' => $product->id,
            'enabled' => false,
            'matching_mode' => $mode->value,
            'location_ids' => $locationIds,
            'wfo' => null,
            'county_zone_codes' => [],
        ];
    }

    /**
     * @return list<MatchingMode>
     */
    private static function weatherAlertModes(): array
    {
        return array_values(array_filter(
            MatchingMode::cases(),
            fn (MatchingMode $mode): bool => $mode !== MatchingMode::RiskArea,
        ));
    }

    private static function hasRiskLevels(Product $product): bool
    {
        return ! empty($product->risk_level_options);
    }

    private static function isLocationBased(MatchingMode $mode): bool
    {
        return in_array($mode, [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::RiskArea], true);
    }

    private static function unsupported(AlertCategory $category): InvalidArgumentException
    {
        return new InvalidArgumentException("The {$category->value} category has no per-product matching.");
    }
}

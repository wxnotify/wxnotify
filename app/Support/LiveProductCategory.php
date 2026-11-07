<?php

namespace App\Support;

use App\Enums\ProductFamily;
use App\Models\Product;

/**
 * The three groups the Live Weather Products page filters by. A product's group comes from its
 * catalog product's family; a product with no catalog product is Other.
 */
enum LiveProductCategory: string
{
    case WarningsAndWatches = 'warnings_and_watches';
    case OutlooksAndDiscussions = 'outlooks_and_discussions';
    case Other = 'other';

    public static function forProduct(?Product $product): self
    {
        return self::forFamily($product?->family);
    }

    public static function forFamily(?ProductFamily $family): self
    {
        return match ($family) {
            ProductFamily::Warning, ProductFamily::Watch, ProductFamily::Advisory, ProductFamily::Statement => self::WarningsAndWatches,
            ProductFamily::SpcWpc, ProductFamily::ForecastDiscussion => self::OutlooksAndDiscussions,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WarningsAndWatches => 'Warnings & watches',
            self::OutlooksAndDiscussions => 'Outlooks & discussions',
            self::Other => 'Other',
        };
    }

    /**
     * The catalog families that belong to this group (empty for Other, which is everything else).
     *
     * @return list<string>
     */
    public function families(): array
    {
        return match ($this) {
            self::WarningsAndWatches => [ProductFamily::Warning->value, ProductFamily::Watch->value, ProductFamily::Advisory->value, ProductFamily::Statement->value],
            self::OutlooksAndDiscussions => [ProductFamily::SpcWpc->value, ProductFamily::ForecastDiscussion->value],
            self::Other => [],
        };
    }
}

<?php

namespace App\Support\Setup;

use App\Models\ChannelType;
use App\Models\DidYouKnow;
use App\Models\Product;
use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;

/**
 * Which of the app's reference-data sets are installed. Product rows and channel types come from
 * migrations; the catalog's categories, the "Did you know" tips and the tracked shapefile layers
 * are filled by seeders (see InstallReferenceData). Channel types are reported for information
 * only: nothing installs them here.
 */
final class ReferenceDataStatus
{
    /**
     * @return list<array{key: string, label: string, count: int, installed: bool}>
     */
    public static function sets(): array
    {
        $channelTypes = ChannelType::query()->count();
        $tips = DidYouKnow::query()->count();

        return [
            [
                'key' => 'product_catalog',
                'label' => 'Product catalog',
                'count' => Product::query()->count(),
                'installed' => self::productCatalogInstalled(),
            ],
            [
                'key' => 'channel_types',
                'label' => 'Channel types',
                'count' => $channelTypes,
                'installed' => $channelTypes > 0,
            ],
            [
                'key' => 'tips',
                'label' => 'Did you know tips',
                'count' => $tips,
                'installed' => $tips > 0,
            ],
            [
                'key' => 'shapefile_layers',
                'label' => 'Shapefile layers',
                'count' => ShapefileLayer::query()->count(),
                'installed' => self::shapefileLayersInstalled(),
            ],
        ];
    }

    /**
     * True when every set the install action can fill is installed. Cheap enough to ask on every
     * request: it short-circuits on the first missing set instead of building sets().
     */
    public static function allInstalled(): bool
    {
        return self::productCatalogInstalled()
            && self::tipsInstalled()
            && self::shapefileLayersInstalled();
    }

    private static function tipsInstalled(): bool
    {
        return DidYouKnow::query()->exists();
    }

    private static function productCatalogInstalled(): bool
    {
        return Product::query()->whereNotNull('category')->exists();
    }

    private static function shapefileLayersInstalled(): bool
    {
        $keys = array_map(fn ($definition): string => $definition->key, ShapefileLayerDefinitions::all());

        return ShapefileLayer::query()->whereIn('key', $keys)->count() === count($keys);
    }
}

<?php

namespace App\Support;

use App\Enums\ProductFamily;
use App\Models\Product;

/**
 * Which subject placeholders can carry a value for which product. The names are the ones
 * App\Actions\Weather\RenderAlertSubject substitutes; keep the two lists in step.
 */
final class SubjectPlaceholders
{
    public const PRODUCT = '{product}';

    public const LOCATION = '{location}';

    public const CATEGORY = '{category}';

    public const UNTIL = '{until}';

    public const STATES = '{states}';

    public const WFO = '{wfo}';

    /**
     * Product, location and category always have a value; until needs VTEC, states needs UGC and
     * wfo is empty for SPC & WPC products. No product (a rule-level subject) offers all of them.
     *
     * @return list<string>
     */
    public static function forProduct(?Product $product): array
    {
        return array_values(array_filter([
            self::PRODUCT,
            self::LOCATION,
            self::CATEGORY,
            $product === null || $product->has_vtec ? self::UNTIL : null,
            $product === null || $product->has_ugc ? self::STATES : null,
            $product === null || $product->family !== ProductFamily::SpcWpc ? self::WFO : null,
        ]));
    }

    public static function helperText(?Product $product): string
    {
        return 'Placeholders: '.implode(' ', self::forProduct($product));
    }
}

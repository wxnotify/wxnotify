<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The text the Products tab shows for recent searches and result rows.
 */
class ProductSearchPresenter
{
    /** The period filter: API value => label. The empty value is "off". */
    public const PERIODS = ['' => 'Any time', 'hour' => 'Past hour', 'day' => 'Past 24 hours', 'week' => 'Past 7 days', 'month' => 'Past 30 days'];

    /**
     * "AFD · MOB · Past 7 days": the text, the type, the office and the period, whichever were used.
     *
     * @param  array{q: string, type_label: ?string, wfo: string, period: string}  $search
     */
    public static function recentLabel(array $search): string
    {
        return implode(' · ', array_filter([
            $search['q'] !== '' ? $search['q'] : null,
            $search['type_label'],
            $search['wfo'] !== '' ? $search['wfo'] : null,
            $search['period'] !== '' ? (self::PERIODS[$search['period']] ?? null) : null,
        ]));
    }

    /**
     * "KMOB · 2 hours ago", or just the time when the product has no office.
     *
     * @param  array<string, mixed>  $product
     */
    public static function subtitle(array $product): string
    {
        $when = Carbon::parse($product['issued_at'])->diffForHumans();

        return filled($product['office'] ?? null) ? $product['office'].' · '.$when : $when;
    }
}

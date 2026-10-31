<?php

namespace App\Actions\Weather;

use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The SPC Public Severe Weather Outlook (catalog code `PWO`) that goes with the current Day 1 outlook:
 * the newest non-test one received on or after the UTC date the Day 1 outlook was issued.
 */
class FindActivePwo
{
    private const CODE = 'PWO';

    /**
     * @param  string|null  $day1IssuedAt  the fetched Day 1 outlook's `issued_at`; unreadable or null means today (UTC)
     */
    public function __invoke(?string $day1IssuedAt): ?IngestedProduct
    {
        $productId = Product::query()->where('code', self::CODE)->value('id');

        if ($productId === null) {
            return null;
        }

        return IngestedProduct::query()
            ->with('product')
            ->where('product_id', $productId)
            ->where('is_test', false)
            ->where('received_at', '>=', $this->issueDay($day1IssuedAt))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    private function issueDay(?string $day1IssuedAt): Carbon
    {
        try {
            return filled($day1IssuedAt) ? Carbon::parse($day1IssuedAt)->utc()->startOfDay() : Carbon::now('UTC')->startOfDay();
        } catch (Throwable) {
            return Carbon::now('UTC')->startOfDay();
        }
    }
}

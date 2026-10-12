<?php

namespace App\Actions\Products;

use App\Models\IngestedProduct;
use App\Models\Product;
use App\Support\AppAlertCursor;
use App\Support\IssuingOffice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A page of the ingested-product archive for the app's Products tab, newest first. The search text matches the
 * catalog name, the AWIPS ID prefix and the WMO header prefix (never the headline or the bulletin text), all
 * case-insensitively because the pipeline stores those codes in lowercase. Test products are hidden in production,
 * as ViewProductController does. Pages are keyed by (received_at, id) so rows with the same time page stably.
 */
class SearchProducts
{
    /**
     * @param  array{q?: ?string, type?: ?int, wfo?: ?string, period?: ?string}  $filters
     * @return array{products: Collection<int, IngestedProduct>, next_cursor: ?string}
     */
    public function __invoke(array $filters, int $limit = 25, ?string $before = null): array
    {
        $cursor = $before === null ? null : AppAlertCursor::decode($before);

        $rows = IngestedProduct::query()
            ->select(['id', 'cccc', 'ttaaii', 'awipsid', 'wmo_ddhhmm', 'received_at', 'source', 'product_id', 'view_slug'])
            ->with('product:id,name,category')
            ->when(app()->isProduction(), fn (Builder $query) => $query->where('is_test', false))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $this->matching($query, (string) $filters['q']))
            ->when(filled($filters['type'] ?? null), fn (Builder $query) => $query->where('product_id', (int) $filters['type']))
            ->when(filled($filters['wfo'] ?? null), function (Builder $query) use ($filters): void {
                $centres = IssuingOffice::centres((string) $filters['wfo']);

                $query->whereRaw('lower(cccc) in ('.implode(', ', array_fill(0, count($centres), '?')).')', $centres);
            })
            ->when(filled($filters['period'] ?? null), fn (Builder $query) => $query->where('received_at', '>=', $this->since((string) $filters['period'])))
            ->when($cursor !== null, fn (Builder $query) => $query->whereRaw('(received_at, id) < (?, ?)', [$cursor['at'], $cursor['id']]))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'products' => $page,
            'next_cursor' => $rows->count() > $limit && $last !== null
                ? AppAlertCursor::encode($last->received_at->format('Y-m-d H:i:s'), $last->id)
                : null,
        ];
    }

    /**
     * @param  Builder<IngestedProduct>  $query
     */
    private function matching(Builder $query, string $text): void
    {
        $escaped = addcslashes(mb_strtolower($text), '%_\\');

        $productIds = Product::query()
            ->whereIn('status', ['active', 'retired'])
            ->whereRaw('lower(name) like ?', ["%{$escaped}%"])
            ->pluck('id');

        $query->where(function (Builder $match) use ($escaped, $productIds): void {
            $match->whereRaw('lower(awipsid) like ?', ["{$escaped}%"])
                ->orWhereRaw("lower(ttaaii || ' ' || cccc) like ?", ["{$escaped}%"]);

            if ($productIds->isNotEmpty()) {
                $match->orWhereIn('product_id', $productIds);
            }
        });
    }

    private function since(string $period): CarbonInterface
    {
        return match ($period) {
            'hour' => now()->subHour(),
            'day' => now()->subDay(),
            'week' => now()->subDays(7),
            'month' => now()->subDays(30),
        };
    }
}

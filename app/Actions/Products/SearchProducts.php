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
 * A page of the ingested-product archive for the app's Products tab, newest first. The search text matches a word
 * of the catalog name that starts with it (so "tor" finds Tornado, not Thunderstorm), the AWIPS ID prefix and the
 * WMO header prefix (never the headline or the bulletin text), all
 * case-insensitively because the pipeline stores those codes in lowercase. The pipeline sets product_id only for
 * VTEC products, AFD and a few SPC/WPC products, so a catalog product whose code is a 3-character AWIPS product
 * category (AFD, TAF, AFM ...) also matches the unclassified rows whose AWIPS ID starts with that category, both for
 * the type filter and for a catalog-name match. Test products are hidden in production, as ViewProductController
 * does. Pages are keyed by (received_at, id) so rows with the same time page stably.
 */
class SearchProducts
{
    /**
     * The AWIPS product category (first 3 characters, lowercase) of a row with no product_id, else NULL. Backed by the
     * ingested_products_unclassified_category_index expression index: keep the two spellings identical.
     */
    private const UNCLASSIFIED_CATEGORY = 'CASE WHEN product_id IS NULL THEN lower(left(awipsid, 3)) END';

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
            ->when(filled($filters['type'] ?? null), fn (Builder $query) => $this->ofType($query, (int) $filters['type']))
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
    private function ofType(Builder $query, int $productId): void
    {
        $product = Product::query()->find($productId, ['id', 'code']);

        $query->where(function (Builder $match) use ($productId, $product): void {
            $match->where('product_id', $productId);

            $this->orUnclassifiedInCategories($match, $product === null ? [] : [$product->code]);
        });
    }

    /**
     * @param  Builder<IngestedProduct>  $query
     */
    private function matching(Builder $query, string $text): void
    {
        $escaped = addcslashes(mb_strtolower($text), '%_\\');
        $withoutSpaces = addcslashes(mb_strtolower((string) preg_replace('/\s+/u', '', $text)), '%_\\');

        $products = Product::query()
            ->whereIn('status', ['active', 'retired'])
            ->where(function (Builder $name) use ($escaped): void {
                foreach (['', '% ', '%-', '%/', '%('] as $beforeWord) {
                    $name->orWhereRaw('lower(name) like ?', ["{$beforeWord}{$escaped}%"]);
                }
            })
            ->get(['id', 'code']);

        $query->where(function (Builder $match) use ($escaped, $withoutSpaces, $products): void {
            $match->whereRaw('lower(awipsid) like ?', ["{$escaped}%"])
                ->orWhereRaw("lower(ttaaii || ' ' || cccc) like ?", ["{$escaped}%"]);

            if ($withoutSpaces !== $escaped && $withoutSpaces !== '') {
                $match->orWhereRaw('lower(awipsid) like ?', ["{$withoutSpaces}%"]);
            }

            if ($products->isNotEmpty()) {
                $match->orWhereIn('product_id', $products->pluck('id'));

                $this->orUnclassifiedInCategories($match, $products->pluck('code')->all());
            }
        });
    }

    /**
     * Adds "no product_id and an AWIPS ID starting with the category" (the AWIPS ID's first 3 characters equal the code) for each code that is a 3-character AWIPS
     * product category. VTEC-style codes (TO.W, SPC.FIRE) never match this way: their rows carry product_id.
     *
     * @param  Builder<IngestedProduct>  $match
     * @param  array<int, string>  $codes
     */
    private function orUnclassifiedInCategories(Builder $match, array $codes): void
    {
        $categories = collect($codes)
            ->filter(fn (string $code): bool => preg_match('/^[A-Za-z0-9]{3}$/', $code) === 1)
            ->map(fn (string $code): string => mb_strtolower($code))
            ->unique()
            ->values();

        if ($categories->isEmpty()) {
            return;
        }

        $match->orWhereRaw(self::UNCLASSIFIED_CATEGORY.' in ('.implode(', ', array_fill(0, $categories->count(), '?')).')', $categories->all());
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

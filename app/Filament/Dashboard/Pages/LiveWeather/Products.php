<?php

namespace App\Filament\Dashboard\Pages\LiveWeather;

use App\Filament\Dashboard\Pages\LiveWeather\Concerns\LimitsDataCalls;
use App\Models\IngestedProduct;
use App\Support\IssuingOffice;
use App\Support\LiveProductCategory;
use App\Support\Timezones;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * The Live Weather product feed: the latest processed products, newest first, kept current by the
 * ProductIngested broadcast. Public data only (names, offices, headlines; never the text); every
 * signed-in tier may open it. Test products and replays are never listed. The mount, the live
 * listener and the gap fill all build rows through buildRows(), so they cannot diverge.
 *
 * @phpstan-type Row array{id: int, name: string, office: ?string, headline: ?string, issued_at: string, category: string, view_url: ?string}
 */
class Products extends Page
{
    use LimitsDataCalls;

    protected const INITIAL_ROWS = 50;

    protected const MAX_ROWS = 100;

    /**
     * Calls per viewer per minute: fetchSince() and setCategories() share one budget; the live
     * listener has its own, larger one (it fires once per broadcast) so a busy feed never starves
     * the others.
     */
    protected const QUERY_CALLS_PER_MINUTE = 120;

    protected const LIVE_CALLS_PER_MINUTE = 600;

    protected string $view = 'filament.dashboard.pages.live-weather.products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Newspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Live Weather';

    protected static ?string $navigationLabel = 'Products';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Live products';

    protected static ?string $slug = 'live-weather/products';

    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $rows = [];

    /**
     * The selected LiveProductCategory values.
     *
     * @var list<string>
     */
    #[Locked]
    public array $categories = [];

    /**
     * Products that arrived while the viewer was scrolled down (the page's Alpine resets it).
     */
    public int $pending = 0;

    public function mount(): void
    {
        $this->categories = array_map(fn (LiveProductCategory $category): string => $category->value, LiveProductCategory::cases());
        $this->rows = $this->buildRows($this->listable()->limit(self::INITIAL_ROWS)->get());
    }

    /**
     * The Livewire listener for the private channel's ProductIngested event. Only the id is used:
     * the page reads the row itself, so a missing, test or replayed product adds nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    public function onProductIngested(array $payload): void
    {
        if ($this->callLimited('live-products-live', self::LIVE_CALLS_PER_MINUTE)) {
            return;
        }

        $id = $payload['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return;
        }

        $id = (int) $id;

        if ($this->hasRow($id)) {
            return;
        }

        $product = $this->listable()->whereKey($id)->first();

        if ($product === null) {
            return;
        }

        $this->mergeRows($this->buildRows(collect([$product])));

        if ($this->hasRow($id)) {
            $this->pending++;
        }
    }

    /**
     * The gap fill: the listable products newer than the given id (newest first, at most 100),
     * merged into the list and returned; each row that actually entered the capped list counts into
     * `pending` (the page zeroes it at the top). A non-numeric or negative id, or a call over the budget,
     * returns nothing without a query.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSince(mixed $lastId): array
    {
        $lastId = is_int($lastId) ? $lastId : (is_string($lastId) && ctype_digit($lastId) && strlen($lastId) <= 18 ? (int) $lastId : -1);

        if ($lastId < 0 || $this->callLimited('live-products-query', self::QUERY_CALLS_PER_MINUTE)) {
            return [];
        }

        $rows = $this->buildRows(
            $this->listable()->where('ingested_products.id', '>', $lastId)->limit(self::MAX_ROWS)->get()
        );

        $known = array_column($this->rows, 'id');
        $this->mergeRows($rows);

        foreach ($rows as $row) {
            if (! in_array($row['id'], $known, true) && $this->hasRow($row['id'])) {
                $this->pending++;
            }
        }

        return $rows;
    }

    /**
     * A non-array argument, any non-string or unknown value, or a call over the budget changes
     * nothing.
     */
    public function setCategories(mixed $categories): void
    {
        if (! is_array($categories) || $this->callLimited('live-products-query', self::QUERY_CALLS_PER_MINUTE)) {
            return;
        }

        $valid = [];

        foreach ($categories as $value) {
            $category = is_string($value) ? LiveProductCategory::tryFrom($value) : null;

            if ($category === null) {
                return;
            }

            $valid[$category->value] = $category->value;
        }

        $this->categories = array_values($valid);
        $this->rows = $this->buildRows($this->listable()->limit(self::INITIAL_ROWS)->get());
        $this->pending = 0;
    }

    /**
     * Whether a row's public page may be linked: always an https URL, and an http URL only
     * outside production (a local APP_URL). Any other scheme, a relative URL or none is never linked.
     */
    public function isLinkable(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        return str_starts_with($url, 'https://')
            || (! app()->isProduction() && str_starts_with($url, 'http://'));
    }

    public function getTimezone(): string
    {
        return Timezones::forUserOrDefault(auth()->user());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getCategoryOptions(): array
    {
        return array_map(fn (LiveProductCategory $category): array => ['value' => $category->value, 'label' => $category->label()], LiveProductCategory::cases());
    }

    /**
     * Real products only (no test product, no replay copy) in the selected categories, newest
     * first. Only the columns a row needs are selected (never the text).
     *
     * @return Builder<IngestedProduct>
     */
    protected function listable(): Builder
    {
        return IngestedProduct::query()
            ->select(['ingested_products.id', 'ingested_products.cccc', 'ingested_products.ttaaii', 'ingested_products.awipsid', 'ingested_products.received_at', 'ingested_products.product_id', 'ingested_products.view_slug'])
            ->where('ingested_products.is_test', false)
            ->whereNull('ingested_products.replay_of_id')
            ->where(function (Builder $query): void {
                $this->whereInSelectedCategories($query);
            })
            ->with(['product:id,name,family', 'segments:id,ingested_product_id,position,product_id,vtec_phenomenon,vtec_significance', 'segments.product:id,name'])
            ->orderByDesc('ingested_products.received_at')
            ->orderByDesc('ingested_products.id');
    }

    /**
     * @param  Builder<IngestedProduct>  $query
     */
    protected function whereInSelectedCategories(Builder $query): void
    {
        $selected = array_filter(array_map(LiveProductCategory::tryFrom(...), $this->categories));

        if ($selected === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $mapped = array_merge(...array_map(fn (LiveProductCategory $category): array => $category->families(), LiveProductCategory::cases()));

        foreach ($selected as $category) {
            $query->orWhere(function (Builder $inner) use ($category, $mapped): void {
                if ($category === LiveProductCategory::Other) {
                    $inner->whereDoesntHave('product', fn (Builder $product) => $product->whereIn('family', $mapped));

                    return;
                }

                $inner->whereHas('product', fn (Builder $product) => $product->whereIn('family', $category->families()));
            });
        }
    }

    /**
     * The one place a row is built (mount, live event and gap fill).
     *
     * @param  Collection<int, IngestedProduct>  $products
     * @return list<array<string, mixed>>
     */
    protected function buildRows(Collection $products): array
    {
        return $products->map(fn (IngestedProduct $product): array => [
            'id' => $product->id,
            'name' => $product->displayTitle(),
            'office' => filled($product->cccc) ? IssuingOffice::code($product->cccc) : null,
            'headline' => $product->segments->isEmpty() ? null : implode(' + ', $product->eventNamesFor($product->segments)),
            'issued_at' => $product->received_at->toIso8601String(),
            'category' => LiveProductCategory::forProduct($product->product)->value,
            'view_url' => $product->viewUrl(),
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $incoming
     */
    protected function mergeRows(array $incoming): void
    {
        $known = array_column($this->rows, 'id');
        $merged = array_merge($this->rows, array_filter($incoming, fn (array $row): bool => ! in_array($row['id'], $known, true)));

        usort($merged, fn (array $a, array $b): int => [strtotime($b['issued_at']), $b['id']] <=> [strtotime($a['issued_at']), $a['id']]);

        $this->rows = array_slice($merged, 0, self::MAX_ROWS);
    }

    protected function hasRow(int $id): bool
    {
        return in_array($id, array_column($this->rows, 'id'), true);
    }
}

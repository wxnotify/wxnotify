# Mobile Products (the Products tab) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Products tab in the mobile app where a subscriber searches the archive of ingested weather products by name or ID, filters by product type, issuing office and time period, sees recent searches, and opens a result's View Product page.

**Architecture:** Two read-only endpoints on the existing `/api/v1` app-token group (`GET /products` over `ingested_products` through a `SearchProducts` action; `GET /product-types` for the filter choices), and a `ProductsScreen` native component (search box, three selects, results with a Show more button, recent searches kept in `LocalCache`). Search runs on submit, never per keystroke. Results and filter lists are never cached; only the recent searches are.

**Tech Stack:** Laravel 12 / Pest / PostgreSQL (backend, repo root); NativePHP v4 SuperNative (app, `mobile/`).

**Spec:** `docs/superpowers/specs/2026-10-11-mobile-products-design.md`

## Global Constraints

- Read `.ai/rules/index.md` and every rule file whose globs match before editing a path (backend paths: `app/Actions/**` → `.ai/rules/actions.md`; `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Http/Resources/**` → `.ai/rules/api.md`; `app/Support/**` → `.ai/rules/support.md`; `tests/Feature/**` → `.ai/rules/feature.md`; `app/Models/**` → `.ai/rules/models.md`).
- Run Pint on the files you changed (`vendor/bin/pint --format agent <files>`), in the directory that owns them (repo root for the backend, `mobile/` for the app).
- Backend tests run in the app container, never on the host (the host cannot resolve the `pgsql` host): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <file>'`. If the container is not running, start the dev stack first; if it cannot be started, stop and report. App tests run on the host: `cd mobile && vendor/bin/pest --compact <file>`.
- Never run `native:run`, `native:jump` or `native:watch`. Never push. Commit directly to `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- The products are public data but the endpoints stay behind the app token. Neither endpoint ever returns the bulletin `text`, `ugc_codes`, `polygon` or any other column than the ones listed in the spec; the search text is never logged.
- Search text: at least 2 characters (422 below that), at most 100. `%` and `_` in it are literal. All matching is case-insensitive; the pipeline stores `cccc`, `ttaaii` and `awipsid` in lowercase.
- Test products (`is_test`) are excluded in production only, as `ViewProductController` does.
- App views: `native.<kebab-class>`; bare tags (`select`, `outlined-text-input`, `pressable`) because the stale `native:validate` does not know them; handler arguments through `{{ }}`; `native:model.blur`, never live sync, for the search box. Style with theme tokens (`bg-theme-*`, `text-theme-*`), never hex.

## Review Focus

- A search such as `%%` or `_f` must match nothing, not everything (LIKE wildcards are literal): Task 1 test.
- Typing in any case (`AFD`, `afd`, `Afd`) finds the lowercase-stored `afdmob`: Task 1 test.
- Many products with the same `received_at` must page without a duplicate or a gap: Task 1 test.
- A product with no catalog entry or no `view_slug` must still list (fallback title, null link) and a row without a link must not be tappable: Task 1 and Task 4 tests.
- One typed character is refused with a message, not sent; a long query is refused by the API (422): Task 1 and Task 4 tests.
- Two catalog products with the same name must both be selectable in the Type list: Task 4 test.
- Going offline or failing mid-search must keep the box, filters and recents: Task 4 test.

---

### Task 1: Backend — `GET /api/v1/products`

**Files:**
- Create: `app/Support/IssuingOffice.php`
- Create: `app/Actions/Products/SearchProducts.php`
- Create: `app/Http/Requests/SearchProductsRequest.php`
- Create: `app/Http/Resources/Api/V1/ProductSearchResultResource.php`
- Create: `app/Http/Controllers/Api/V1/ProductController.php`
- Modify: `app/Models/IngestedProduct.php` (add `displayTitle()`)
- Modify: `app/Http/Controllers/ViewProductController.php` (use `displayTitle()`)
- Modify: `routes/api.php`
- Modify: `.ai/rules/api.md`, `.ai/rules/actions.md`
- Test: `tests/Feature/ApiProductsTest.php`

**Interfaces:**
- Produces: `GET /api/v1/products?q=&type=&wfo=&period=&before=&limit=` → `{data: [{id, title, office, issued_at, source, product_id, type_label, view_url}], meta: {next_cursor}}`; `SearchProducts::__invoke(array $filters, int $limit = 25, ?string $before = null): array{products: Collection<int, IngestedProduct>, next_cursor: ?string}`; `IssuingOffice::centres(string $wfo): list<string>`; `IngestedProduct::displayTitle(): string`.
- Consumes: `AppAlertCursor::encode/decode` (cursor, `Y-m-d H:i:s` + id), `GenerateViewSlug::issuedAt()`, `IngestedProduct::viewUrl()`, `ProductCategory::label()`.

- [ ] **Step 1: Read the rule files** named in Global Constraints for the paths above.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/ApiProductsTest.php`:

```php
<?php

use App\Enums\ProductCategory;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");

    foreach ([['MOB', 'Mobile, AL'], ['HFO', 'Honolulu, HI'], ['SJU', 'San Juan, PR'], ['OKX', 'New York, NY']] as [$cwa, $citystate]) {
        DB::table('cwa_boundaries')->insert(['wfo' => $cwa, 'cwa' => $cwa, 'citystate' => $citystate, 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->discussion = Product::factory()->create(['name' => 'Area Forecast Discussion', 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports]);

    $this->make = fn (array $overrides = []) => IngestedProduct::factory()->create([
        'cccc' => 'kmob', 'ttaaii' => 'fxus64', 'awipsid' => 'afdmob', 'wmo_ddhhmm' => '111355',
        'received_at' => now()->subHour(), 'product_id' => $this->discussion->id,
        ...$overrides,
    ]);

    $this->search = fn (array $query = []) => $this->withToken($this->token)->getJson('/api/v1/products?'.http_build_query($query));
});

test('it needs an app token', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    $this->withToken($beacon)->getJson('/api/v1/products')->assertForbidden();
});

test('with no filters every product is listed newest first, in the documented shape', function () {
    $older = ($this->make)(['received_at' => now()->subHours(3)]);
    $newer = ($this->make)(['received_at' => now()->subHour(), 'awipsid' => 'afdhfo', 'cccc' => 'phfo']);

    $response = ($this->search)()->assertOk();

    $response->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.title', 'Area Forecast Discussion')
        ->assertJsonPath('data.0.office', 'PHFO')
        ->assertJsonPath('data.0.product_id', $this->discussion->id)
        ->assertJsonPath('data.0.type_label', 'Other Forecasts, Outlooks, Discussions, and Reports')
        ->assertJsonPath('data.0.source', 'nwws')
        ->assertJsonPath('data.0.view_url', $newer->viewUrl())
        ->assertJsonPath('meta.next_cursor', null);

    expect($response->json('data.0.issued_at'))->toBeString();
});

test('the response never carries the bulletin text or other columns', function () {
    ($this->make)(['text' => 'SECRET-BULLETIN-BODY', 'ugc_codes' => ['ALC097'], 'polygon' => [[1, 2]]]);

    $response = ($this->search)()->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'title', 'office', 'issued_at', 'source', 'product_id', 'type_label', 'view_url'])
        ->and($response->getContent())->not->toContain('SECRET-BULLETIN-BODY')->not->toContain('ALC097');
});

test('a product with no catalog entry and no view slug still lists with a fallback title and no link', function () {
    $bare = ($this->make)(['product_id' => null, 'awipsid' => null, 'ttaaii' => null]);
    IngestedProduct::query()->whereKey($bare->id)->update(['view_slug' => null]);

    ($this->search)()->assertOk()
        ->assertJsonPath('data.0.title', 'Weather product')
        ->assertJsonPath('data.0.type_label', null)
        ->assertJsonPath('data.0.view_url', null);
});

test('the title falls back to the AWIPS ID, then the WMO header code', function () {
    ($this->make)(['product_id' => null, 'awipsid' => 'afdmob', 'received_at' => now()->subMinutes(10)]);
    ($this->make)(['product_id' => null, 'awipsid' => null, 'ttaaii' => 'fxus64', 'received_at' => now()->subMinutes(20)]);

    ($this->search)()->assertOk()
        ->assertJsonPath('data.0.title', 'AFDMOB')
        ->assertJsonPath('data.1.title', 'FXUS64');
});

test('the search text matches a catalog name, an AWIPS ID prefix or a WMO header prefix, in any case', function () {
    $byName = ($this->make)(['awipsid' => 'zzzzzz', 'ttaaii' => 'aaaaaa']);
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    $byAwips = ($this->make)(['product_id' => $warning->id, 'awipsid' => 'tormob', 'ttaaii' => 'wfus54']);
    $byHeader = ($this->make)(['product_id' => $warning->id, 'awipsid' => 'pnsmob', 'ttaaii' => 'noxx99', 'cccc' => 'kbmx']);

    $ids = fn (string $q) => collect(($this->search)(['q' => $q])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('forecast disc'))->toBe([$byName->id])
        ->and($ids('FORECAST DISC'))->toBe([$byName->id])
        ->and($ids('TORM'))->toBe([$byAwips->id])
        ->and($ids('tormob'))->toBe([$byAwips->id])
        ->and($ids('NOXX99 KB'))->toBe([$byHeader->id])
        ->and($ids('nothing matches this'))->toBe([]);
});

test('percent and underscore in the search text are literal', function () {
    ($this->make)(['awipsid' => 'afdmob']);

    expect(($this->search)(['q' => '%%'])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => '_f'])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => 'a_d'])->assertOk()->json('data'))->toBe([]);
});

test('the search text needs 2 to 100 characters', function () {
    ($this->search)(['q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('q');
    ($this->search)(['q' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('q');
    ($this->search)(['q' => 'ab'])->assertOk();
});

test('the type filter returns only that catalog product', function () {
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    ($this->make)();
    $tornado = ($this->make)(['product_id' => $warning->id]);

    $ids = collect(($this->search)(['type' => $warning->id])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$tornado->id]);
});

test('the office filter matches the issuing centre by its K or P prefix and the San Juan exception', function () {
    $mobile = ($this->make)(['cccc' => 'kmob']);
    $honolulu = ($this->make)(['cccc' => 'phfo']);
    $sanJuan = ($this->make)(['cccc' => 'tjsj']);
    ($this->make)(['cccc' => 'kokx']);

    $ids = fn (string $wfo) => collect(($this->search)(['wfo' => $wfo])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('MOB'))->toBe([$mobile->id])
        ->and($ids('hfo'))->toBe([$honolulu->id])
        ->and($ids('SJU'))->toBe([$sanJuan->id]);
});

test('an unknown office code is a validation error', function () {
    ($this->search)(['wfo' => 'ZZZ'])->assertUnprocessable()->assertJsonValidationErrors('wfo');
    ($this->search)(['wfo' => 'MOBILE'])->assertUnprocessable()->assertJsonValidationErrors('wfo');
});

test('each time period keeps only products received within it', function () {
    ($this->make)(['received_at' => now()->subMinutes(30)]);
    ($this->make)(['received_at' => now()->subHours(3)]);
    ($this->make)(['received_at' => now()->subDays(3)]);
    ($this->make)(['received_at' => now()->subDays(20)]);
    ($this->make)(['received_at' => now()->subDays(40)]);

    $count = fn (string $period) => count(($this->search)(['period' => $period])->assertOk()->json('data'));

    expect($count('hour'))->toBe(1)
        ->and($count('day'))->toBe(2)
        ->and($count('week'))->toBe(3)
        ->and($count('month'))->toBe(4)
        ->and(count(($this->search)()->json('data')))->toBe(5);

    ($this->search)(['period' => 'year'])->assertUnprocessable()->assertJsonValidationErrors('period');
});

test('filters combine with AND', function () {
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    $match = ($this->make)(['product_id' => $warning->id, 'cccc' => 'kmob', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $warning->id, 'cccc' => 'phfo', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $this->discussion->id, 'cccc' => 'kmob', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $warning->id, 'cccc' => 'kmob', 'received_at' => now()->subDays(2)]);

    $ids = collect(($this->search)(['type' => $warning->id, 'wfo' => 'MOB', 'period' => 'day'])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$match->id]);
});

test('pages follow the cursor without a duplicate or a gap, even when received times tie', function () {
    $tied = now()->subHour();
    $all = collect(range(1, 5))->map(fn () => ($this->make)(['received_at' => $tied]))->reverse()->pluck('id')->all();

    $first = ($this->search)(['limit' => 2])->assertOk();
    $second = ($this->search)(['limit' => 2, 'before' => $first->json('meta.next_cursor')])->assertOk();
    $third = ($this->search)(['limit' => 2, 'before' => $second->json('meta.next_cursor')])->assertOk();

    expect(collect([$first, $second, $third])->flatMap(fn ($page) => collect($page->json('data'))->pluck('id'))->all())->toBe($all)
        ->and($first->json('meta.next_cursor'))->not->toBeNull()
        ->and($third->json('meta.next_cursor'))->toBeNull();
});

test('a malformed cursor or an out-of-range limit is a validation error', function () {
    ($this->search)(['before' => 'not-a-cursor'])->assertUnprocessable()->assertJsonValidationErrors('before');
    ($this->search)(['limit' => 51])->assertUnprocessable()->assertJsonValidationErrors('limit');
    ($this->search)(['limit' => 0])->assertUnprocessable()->assertJsonValidationErrors('limit');
});

test('test products are listed outside production and hidden in production', function () {
    $test = ($this->make)(['is_test' => true]);
    $real = ($this->make)(['received_at' => now()->subMinutes(5)]);

    expect(collect(($this->search)()->json('data'))->pluck('id')->all())->toBe([$real->id, $test->id]);

    app()->instance('env', 'production');

    expect(collect(($this->search)()->json('data'))->pluck('id')->all())->toBe([$real->id]);
});

test('the route is rate limited like the rest of the app API', function () {
    ($this->search)()->assertOk()->assertHeader('X-RateLimit-Limit');
});
```

- [ ] **Step 3: Run the tests and verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact tests/Feature/ApiProductsTest.php'`
Expected: FAIL (404 on `/api/v1/products`).

- [ ] **Step 4: Implement**

Create `app/Support/IssuingOffice.php`:

```php
<?php

namespace App\Support;

/**
 * Maps an NWS office code (the `cwa` that `GET /nws-offices` lists) to the WMO issuing centres (`cccc`) that publish for it.
 */
final class IssuingOffice
{
    /** Offices whose centre code is not "K" or "P" followed by the office code. */
    private const EXCEPTIONS = ['SJU' => ['tjsj']];

    /**
     * @return list<string> lowercase, as `ingested_products.cccc` is stored
     */
    public static function centres(string $wfo): array
    {
        $code = strtolower($wfo);

        return [...(self::EXCEPTIONS[strtoupper($wfo)] ?? []), 'k'.$code, 'p'.$code];
    }
}
```

In `app/Models/IngestedProduct.php`, add above `viewUrl()`:

```php
    /**
     * The product's name for people: its catalog name, else its AWIPS ID, else its WMO header code, else a generic label.
     */
    public function displayTitle(): string
    {
        return $this->product?->name
            ?? match (true) {
                filled($this->awipsid) => strtoupper($this->awipsid),
                filled($this->ttaaii) => strtoupper($this->ttaaii),
                default => 'Weather product',
            };
    }
```

In `app/Http/Controllers/ViewProductController.php`, replace `$title = $this->title($product);` with `$title = $product->displayTitle();` and delete the private `title()` method.

Create `app/Actions/Products/SearchProducts.php`:

```php
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
```

Create `app/Http/Requests/SearchProductsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\AppAlertCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('wfo'))) {
            $this->merge(['wfo' => strtoupper($this->input('wfo'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'integer', 'min:1'],
            'wfo' => ['nullable', 'string', Rule::exists('cwa_boundaries', 'cwa')],
            'period' => ['nullable', 'string', Rule::in(['hour', 'day', 'week', 'month'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'before' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (AppAlertCursor::decode((string) $value) === null) {
                    $fail('The cursor is not valid.');
                }
            }],
        ];
    }
}
```

Create `app/Http/Resources/Api/V1/ProductSearchResultResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One archived product in a search. Public fields only: never the bulletin text, zones or polygons.
 *
 * @property IngestedProduct $resource
 */
class ProductSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $issuedAt = app(GenerateViewSlug::class)->issuedAt($product) ?? $product->received_at;

        return [
            'id' => $product->id,
            'title' => $product->displayTitle(),
            'office' => filled($product->cccc) ? strtoupper($product->cccc) : null,
            'issued_at' => $issuedAt->toIso8601String(),
            'source' => $product->source,
            'product_id' => $product->product_id,
            'type_label' => $product->product?->category?->label(),
            'view_url' => $product->viewUrl(),
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/ProductController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Products\SearchProducts;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProductsRequest;
use App\Http\Resources\Api\V1\ProductSearchResultResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(SearchProductsRequest $request, SearchProducts $search): AnonymousResourceCollection
    {
        $page = $search(
            $request->safe()->only(['q', 'type', 'wfo', 'period']),
            (int) $request->validated('limit', 25),
            $request->validated('before'),
        );

        return ProductSearchResultResource::collection($page['products'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor']]]);
    }
}
```

In `routes/api.php`, inside the app-token group (next to `/nws-offices`) add `Route::get('/products', [ProductController::class, 'index'])->name('api.v1.products.index');` and the matching `use App\Http\Controllers\Api\V1\ProductController;`.

Append to `.ai/rules/api.md`:

```markdown

## The product search returns public product fields only, never the bulletin text, and is not logged
`GET /api/v1/products` searches the archive of ingested products (public data, but behind the app token). It needs a search text of 2 to 100 characters, caps a page at 50, and matches only the catalog name, the AWIPS ID prefix and the WMO header prefix (case-insensitively: the pipeline stores `cccc`, `ttaaii` and `awipsid` in lowercase; `%` and `_` are literal). It never returns `text`, zones, polygons or any column beyond `ProductSearchResultResource`, and nothing logs or stores what was searched. The office filter maps an office code to its issuing centres through `App\Support\IssuingOffice` (K or P prefix, plus `SJU` → `TJSJ`). Test products are hidden in production only.
```

Append to `.ai/rules/actions.md`:

```markdown

## SearchProducts owns the product archive search and mirrors the product page's test-product rule
`App\Actions\Products\SearchProducts` is the only query behind `GET /api/v1/products`. It selects only the listed columns (never `text`), pages by `(received_at, id)` through `AppAlertCursor`, and hides `is_test` products in production like `ViewProductController`. A change to what the archive search matches belongs here.
```

- [ ] **Step 5: Run the tests and verify they pass**

Run the same command as Step 3, plus the existing page tests: `vendor/bin/pest --compact tests/Feature/ApiProductsTest.php tests/Feature/ViewProductPageTest.php`.
Expected: PASS. If a test fails on the fixture (for example an unexpected ordering from `now()->subHour()` ties), fix the test only when the spec's behavior is still met; otherwise fix the code.

- [ ] **Step 6: Measure on the dev archive (when the dev database is reachable)**

Run in the app container: `php artisan tinker --execute 'DB::enableQueryLog(); foreach ([["q"=>"afd"],["q"=>"forecast"],["wfo"=>"MOB"],["type"=>1,"period"=>"week"],["q"=>"afd","wfo"=>"MOB","period"=>"month"]] as $f) { $t=microtime(true); app(App\Actions\Products\SearchProducts::class)($f); echo json_encode($f)." ".round((microtime(true)-$t)*1000)."ms\n"; } echo DB::table("ingested_products")->count()." rows\n";'`. Also check that the office list is covered: `foreach (DB::table("cwa_boundaries")->pluck("cwa") as $cwa) { if (! DB::table("ingested_products")->whereRaw("lower(cccc) in (?, ?)", App\Support\IssuingOffice::centres($cwa))->exists()) echo $cwa." "; }` and note any office with no products that has a different centre code in `ingested_products` (add it to `IssuingOffice::EXCEPTIONS` with a test line). If any combination takes over 300 ms, add one migration with expression indexes on the columns that scan (`lower(awipsid) text_pattern_ops`, `lower(cccc)`) and re-measure; if the database is unreachable, record that in the report and move on.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --format agent app/Support/IssuingOffice.php app/Actions/Products app/Http/Requests/SearchProductsRequest.php app/Http/Resources/Api/V1/ProductSearchResultResource.php app/Http/Controllers/Api/V1/ProductController.php app/Http/Controllers/ViewProductController.php app/Models/IngestedProduct.php routes/api.php tests/Feature/ApiProductsTest.php
git add app routes tests .ai
git commit -m "API: GET /products searches the product archive by name or ID, type, office and period

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Backend — `GET /api/v1/product-types`

**Files:**
- Create: `app/Actions/Products/ListProductTypes.php`
- Modify: `app/Http/Controllers/Api/V1/ProductController.php` (add `types()`)
- Modify: `routes/api.php`
- Modify: `.ai/rules/api.md` (extend the Task 1 paragraph by one sentence)
- Test: `tests/Feature/ApiProductTypesTest.php`

**Interfaces:**
- Produces: `GET /api/v1/product-types` → `{data: [{category: string, label: string, products: [{id: int, name: string}]}]}`, categories in `ProductCategory` case order, products by `sort_order` then name; active and retired catalog products only; products with no category land in a final group `{category: 'other', label: 'Other'}`.
- Consumes: `Product`, `ProductCategory`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ApiProductTypesTest.php`:

```php
<?php

use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
});

test('it needs an app token', function () {
    $this->getJson('/api/v1/product-types')->assertUnauthorized();
});

test('the types are grouped by category in the dashboard order, active and retired only', function () {
    Product::query()->delete();

    $winter = Product::factory()->create(['name' => 'Winter Storm Warning', 'category' => ProductCategory::WinterWeather, 'sort_order' => 2]);
    $tornado = Product::factory()->create(['name' => 'Tornado Warning', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'sort_order' => 1]);
    $retired = Product::factory()->retired()->create(['name' => 'Old Tornado Product', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'sort_order' => 9]);
    Product::factory()->create(['name' => 'Draft Product', 'category' => ProductCategory::WinterWeather, 'status' => ProductStatus::Draft]);
    $uncategorised = Product::factory()->create(['name' => 'Misc Product', 'category' => null]);

    $response = $this->withToken($this->token)->getJson('/api/v1/product-types')->assertOk();

    expect($response->json('data'))->toBe([
        ['category' => 'tornado_and_severe_thunderstorm', 'label' => 'Tornado and Severe Thunderstorm', 'products' => [
            ['id' => $tornado->id, 'name' => 'Tornado Warning'],
            ['id' => $retired->id, 'name' => 'Old Tornado Product'],
        ]],
        ['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => $winter->id, 'name' => 'Winter Storm Warning']]],
        ['category' => 'other', 'label' => 'Other', 'products' => [['id' => $uncategorised->id, 'name' => 'Misc Product']]],
    ]);
});
```

- [ ] **Step 2: Run it and verify it fails** (404), command as in Task 1 Step 3 with this file.

- [ ] **Step 3: Implement**

Create `app/Actions/Products/ListProductTypes.php`:

```php
<?php

namespace App\Actions\Products;

use App\Enums\ProductCategory;
use App\Models\Product;

/**
 * The catalog products a subscriber can filter the archive search by, grouped by category in the dashboard's
 * section order. Retired products stay (archived rows still point at them); drafts do not.
 */
class ListProductTypes
{
    /**
     * @return list<array{category: string, label: string, products: list<array{id: int, name: string}>}>
     */
    public function __invoke(): array
    {
        $byCategory = Product::query()
            ->whereIn('status', ['active', 'retired'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'category'])
            ->groupBy(fn (Product $product): string => $product->category?->value ?? 'other');

        $groups = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $byCategory->has($category->value))
            ->map(fn (ProductCategory $category): array => $this->group($category->value, $category->label(), $byCategory->get($category->value)));

        if ($byCategory->has('other')) {
            $groups->push($this->group('other', 'Other', $byCategory->get('other')));
        }

        return $groups->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array{category: string, label: string, products: list<array{id: int, name: string}>}
     */
    private function group(string $category, string $label, $products): array
    {
        return [
            'category' => $category,
            'label' => $label,
            'products' => $products->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])->values()->all(),
        ];
    }
}
```

Add to `ProductController`:

```php
    public function types(ListProductTypes $types): JsonResponse
    {
        return response()->json(['data' => $types()]);
    }
```
with `use App\Actions\Products\ListProductTypes;` and `use Illuminate\Http\JsonResponse;`. Add the route `Route::get('/product-types', [ProductController::class, 'types'])->name('api.v1.product-types');` beside `/products`. Append to the `.ai/rules/api.md` paragraph from Task 1: ` \`GET /api/v1/product-types\` lists the catalog products (active and retired, never drafts) grouped by category for the filter.`

- [ ] **Step 4: Run Task 1's and Task 2's test files; expected PASS.**

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --format agent app/Actions/Products app/Http/Controllers/Api/V1/ProductController.php routes/api.php tests/Feature/ApiProductTypesTest.php
git add app routes tests .ai
git commit -m "API: GET /product-types lists the catalog products for the archive filter

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: App — API client, recent searches and presenter

**Files (all under `mobile/`):**
- Modify: `app/Services/WxNotifyApi.php`
- Modify: `app/Support/LocalCache.php`
- Create: `app/Support/ProductSearchPresenter.php`
- Test: `tests/Feature/WxNotifyApiProductsTest.php`, `tests/Feature/LocalCacheSearchesTest.php`, `tests/Feature/ProductSearchPresenterTest.php`

**Interfaces:**
- Produces: `WxNotifyApi::products(array $filters, ?string $before = null, int $limit = 25): array{data: list<array>, meta: array{next_cursor: ?string}}` (`$filters` keys `q`, `type`, `wfo`, `period`; null and empty values are not sent); `WxNotifyApi::productTypes(): list<array{category, label, products: list<array{id, name}>}>`; `LocalCache::recentSearches(): list<array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}>`, `rememberSearch(array $search): void` (newest first, de-duplicated on text case-insensitively + type + wfo + period, at most 10), `forgetSearches(): void`, and `wipe()` also forgets them; `ProductSearchPresenter::PERIODS` (`'' => 'Any time', 'hour' => 'Past hour', 'day' => 'Past 24 hours', 'week' => 'Past 7 days', 'month' => 'Past 30 days'`), `recentLabel(array $search): string`, `subtitle(array $product): string`.
- Consumes: `WxNotifyApi::send()/data()`, `Carbon`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/WxNotifyApiProductsTest.php`:

```php
<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => (new TokenStore)->put('9|secret'));

test('a product search sends only the filters that are set, with the limit and cursor, and returns data and meta', function () {
    Http::fake(['wx.test/api/v1/products*' => Http::response(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'abc']])]);

    $page = app(WxNotifyApi::class)->products(['q' => 'afd', 'type' => null, 'wfo' => 'MOB', 'period' => ''], 'cur', 10);

    expect($page)->toBe(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'abc']]);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://wx.test/api/v1/products?')
            && $query === ['q' => 'afd', 'wfo' => 'MOB', 'limit' => '10', 'before' => 'cur']
            && $request->hasHeader('Authorization', 'Bearer 9|secret');
    });
});

test('a search the API rejects throws a validation failure, and a dead connection reads as offline', function () {
    Http::fake(['wx.test/api/v1/products*' => Http::response(['message' => 'bad', 'errors' => ['q' => ['The q field must be at least 2 characters.']]], 422)]);

    expect(fn () => app(WxNotifyApi::class)->products(['q' => 'a']))->toThrow(ValidationFailed::class);

    $this->network->connected = false;

    expect(fn () => app(WxNotifyApi::class)->products([]))->toThrow(Offline::class);
});

test('the product types are fetched and unwrapped from data', function () {
    Http::fake(['wx.test/api/v1/product-types' => Http::response(['data' => [['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => 3, 'name' => 'Winter Storm Warning']]]]])]);

    expect(app(WxNotifyApi::class)->productTypes()[0]['products'][0])->toBe(['id' => 3, 'name' => 'Winter Storm Warning']);
});
```

`tests/Feature/LocalCacheSearchesTest.php`:

```php
<?php

use App\Support\LocalCache;

function recentSearch(string $q = 'afd', array $overrides = []): array
{
    return ['q' => $q, 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '', ...$overrides];
}

test('searches are remembered newest first, and a repeat moves to the top instead of duplicating', function () {
    $cache = new LocalCache;

    expect($cache->recentSearches())->toBe([]);

    $cache->rememberSearch(recentSearch('afd'));
    $cache->rememberSearch(recentSearch('tornado'));
    $cache->rememberSearch(recentSearch('AFD'));

    expect(array_column($cache->recentSearches(), 'q'))->toBe(['AFD', 'tornado']);
});

test('the same text with a different filter is a different search', function () {
    $cache = new LocalCache;

    $cache->rememberSearch(recentSearch('afd'));
    $cache->rememberSearch(recentSearch('afd', ['wfo' => 'MOB']));
    $cache->rememberSearch(recentSearch('afd', ['type' => 4, 'type_label' => 'Tornado Warning']));
    $cache->rememberSearch(recentSearch('afd', ['period' => 'week']));

    expect($cache->recentSearches())->toHaveCount(4);
});

test('only the last 10 searches are kept', function () {
    $cache = new LocalCache;

    foreach (range(1, 12) as $n) {
        $cache->rememberSearch(recentSearch("search {$n}"));
    }

    $kept = array_column($cache->recentSearches(), 'q');

    expect($kept)->toHaveCount(10)->and($kept[0])->toBe('search 12')->and($kept[9])->toBe('search 3');
});

test('searches can be cleared, and sign-out wipes them', function () {
    $cache = new LocalCache;
    $cache->rememberSearch(recentSearch());

    $cache->forgetSearches();

    expect($cache->recentSearches())->toBe([]);

    $cache->rememberSearch(recentSearch());
    $cache->wipe();

    expect($cache->recentSearches())->toBe([]);
});
```

`tests/Feature/ProductSearchPresenterTest.php`:

```php
<?php

use App\Support\ProductSearchPresenter;

test('a recent search reads as its text, type, office and period joined by dots', function () {
    expect(ProductSearchPresenter::recentLabel(['q' => 'AFD', 'type' => null, 'type_label' => null, 'wfo' => 'MOB', 'period' => 'week']))->toBe('AFD · MOB · Past 7 days')
        ->and(ProductSearchPresenter::recentLabel(['q' => '', 'type' => 4, 'type_label' => 'Tornado Warning', 'wfo' => '', 'period' => '']))->toBe('Tornado Warning')
        ->and(ProductSearchPresenter::recentLabel(['q' => 'afd', 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '']))->toBe('afd');
});

test('a result row reads as its office and how long ago it was issued, or just the time without an office', function () {
    $this->freezeTime();

    expect(ProductSearchPresenter::subtitle(['office' => 'KMOB', 'issued_at' => now()->subHours(2)->toIso8601String()]))->toBe('KMOB · 2 hours ago')
        ->and(ProductSearchPresenter::subtitle(['office' => null, 'issued_at' => now()->subMinutes(5)->toIso8601String()]))->toBe('5 minutes ago');
});
```

- [ ] **Step 2: Run them and verify they fail** (`cd mobile && vendor/bin/pest --compact tests/Feature/WxNotifyApiProductsTest.php tests/Feature/LocalCacheSearchesTest.php tests/Feature/ProductSearchPresenterTest.php`).

- [ ] **Step 3: Implement**

In `app/Services/WxNotifyApi.php`, after `nwsOffices()` add:

```php
    /**
     * A page of the product archive search. Filters that are null or empty are not sent.
     *
     * @param  array{q?: ?string, type?: ?int, wfo?: ?string, period?: ?string}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function products(array $filters, ?string $before = null, int $limit = 25): array
    {
        $query = array_filter(
            [...$filters, 'limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null && $value !== '',
        );

        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/products', $query));

        $this->data($body);

        return $body;
    }

    /**
     * The catalog products the archive search can filter by, grouped by category.
     *
     * @return array<int, array{category: string, label: string, products: array<int, array{id: int, name: string}>}>
     */
    public function productTypes(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/product-types')));
    }
```

In `app/Support/LocalCache.php` add the constants `private const SEARCHES = 'wxnotify.product_searches';` and `private const MAX_SEARCHES = 10;`, then before `wipe()`:

```php
    /**
     * The last searches made in the Products tab, newest first. Kept on the phone only.
     *
     * @return array<int, array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}>
     */
    public function recentSearches(): array
    {
        return Cache::get(self::SEARCHES, []);
    }

    /**
     * @param  array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}  $search
     */
    public function rememberSearch(array $search): void
    {
        $key = fn (array $entry): string => json_encode([mb_strtolower($entry['q']), $entry['type'], $entry['wfo'], $entry['period']]);

        $others = array_filter($this->recentSearches(), fn (array $entry): bool => $key($entry) !== $key($search));

        Cache::forever(self::SEARCHES, array_slice([$search, ...array_values($others)], 0, self::MAX_SEARCHES));
    }

    public function forgetSearches(): void
    {
        Cache::forget(self::SEARCHES);
    }
```
and in `wipe()` add `Cache::forget(self::SEARCHES);`. Update the class docblock sentence to mention the recent product searches.

Create `app/Support/ProductSearchPresenter.php`:

```php
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
```

- [ ] **Step 4: Run the three files plus `LocalCacheTest.php` and `LocalCacheMapTest.php`; expected PASS.**

- [ ] **Step 5: Pint and commit**

```bash
cd mobile && vendor/bin/pint --format agent app/Services/WxNotifyApi.php app/Support/LocalCache.php app/Support/ProductSearchPresenter.php tests/Feature/WxNotifyApiProductsTest.php tests/Feature/LocalCacheSearchesTest.php tests/Feature/ProductSearchPresenterTest.php
git add -A . && git commit -m "App: products search API client, recent searches in the local cache, presenter

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: App — the Products tab and screen

**Files (all under `mobile/`):**
- Create: `app/NativeComponents/ProductsScreen.php`
- Create: `resources/views/native/products-screen.blade.php`
- Modify: `resources/views/native/partials/tabs.blade.php`
- Modify: `routes/mobile.php`
- Modify: `tests/Feature/ScreensRenderTest.php`
- Test: `tests/Feature/ProductsScreenTest.php`

**Interfaces:**
- Consumes: Task 3's `WxNotifyApi::products()/productTypes()`, `nwsOffices()`, `LocalCache` searches, `ProductSearchPresenter`; `RequiresSignIn`; `Browser::inApp()`; `SignOut::clearLocal()`.
- Produces: the `/products` route (`ProductsScreen`) and the Products tab (`id="products"`, between Map and Settings).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ProductsScreenTest.php`:

```php
<?php

use App\NativeComponents\ProductsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function productRow(int $id, array $overrides = []): array
{
    return [
        'id' => $id, 'title' => "Area Forecast Discussion {$id}", 'office' => 'KMOB', 'issued_at' => now()->subHours(2)->toIso8601String(),
        'source' => 'nwws', 'product_id' => 4, 'type_label' => 'Other Discussions', 'view_url' => "https://wx.test/view-product/p{$id}",
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $products  the /products response body (default: two rows, no next page)
 */
function fakeProducts(?array $products = null): void
{
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => [
            ['category' => 'tornado_and_severe_thunderstorm', 'label' => 'Tornado and Severe Thunderstorm', 'products' => [['id' => 4, 'name' => 'Tornado Warning'], ['id' => 5, 'name' => 'Tornado Warning']]],
            ['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => 6, 'name' => 'Winter Storm Warning']]],
        ]]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => [['code' => 'MOB', 'label' => 'MOB — Mobile, AL'], ['code' => 'HFO', 'label' => 'HFO — Honolulu, HI']]]),
        'wx.test/api/v1/products*' => Http::response($products ?? ['data' => [productRow(1), productRow(2)], 'meta' => ['next_cursor' => null]]),
    ]);
}

/**
 * @return array<string, string> the query string of the last /products request
 */
function lastProductsQuery(): array
{
    $query = [];

    Http::assertSent(function (Request $request) use (&$query) {
        if (str_contains($request->url(), '/api/v1/products')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        }

        return true;
    });

    return $query;
}

beforeEach(function () {
    $this->freezeTime();
    (new TokenStore)->put('9|secret');
});

test('it opens with the search box, the filters and no results', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->assertNavTitle('Products')
        ->assertHasTab('Products')
        ->assertTabActive('Products')
        ->assertElement('outlined_text_input')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Type')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Office')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Period')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Search')
        ->assertDontSee('Area Forecast Discussion')
        ->assertDontSee('Clear filters')
        ->assertSee('Search by name or ID');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/products'));
});

test('the keyboard Search key and the Search button both run the typed search', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('Area Forecast Discussion 1')
        ->assertSee('KMOB · 2 hours ago');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'limit' => '25']);

    Native::test(ProductsScreen::class)
        ->input('field-query', 'tornado')
        ->tap('search-products')
        ->assertSee('Area Forecast Discussion 2');

    expect(lastProductsQuery())->toBe(['q' => 'tornado', 'limit' => '25']);
});

test('one typed character is refused with a message and nothing is sent', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'a')
        ->assertSee('Type at least 2 characters to search')
        ->assertDontSee('Area Forecast Discussion');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/products'));
});

test('a filter alone runs a search at once, and each filter is sent as the API expects', function () {
    fakeProducts();

    $screen = Native::test(ProductsScreen::class)
        ->select('field-office', 'MOB — Mobile, AL')
        ->assertSee('Area Forecast Discussion 1');

    expect(lastProductsQuery())->toBe(['wfo' => 'MOB', 'limit' => '25']);

    $screen->select('field-period', 'Past 7 days')->select('field-type', 'Winter Storm Warning');

    expect(lastProductsQuery())->toBe(['type' => '6', 'wfo' => 'MOB', 'period' => 'week', 'limit' => '25']);
});

test('two catalog products with the same name can both be chosen', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)->select('field-type', 'Tornado Warning (5)');

    expect(lastProductsQuery())->toBe(['type' => '5', 'limit' => '25']);
});

test('clearing the filters, with no text, goes back to the opening state', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->select('field-period', 'Past hour')
        ->assertSee('Clear filters')
        ->tap('clear-filters')
        ->assertDontSee('Area Forecast Discussion')
        ->assertDontSee('Clear filters')
        ->assertSee('Search by name or ID');
});

test('a row opens its product page in the in-app browser, and a row without a page is not tappable', function () {
    fakeProducts(['data' => [productRow(1), productRow(2, ['view_url' => null])], 'meta' => ['next_cursor' => null]]);

    Native::test(ProductsScreen::class)->submit('field-query', 'afd')->tap('product-1');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/p1']);

    Native::test(ProductsScreen::class)->submit('field-query', 'afd')
        ->assertMissingElement('pressable', fn (array $pressable) => ($pressable['ref'] ?? null) === 'product-2')
        ->assertSee('Area Forecast Discussion 2');
});

test('no matches says so and keeps the filters', function () {
    fakeProducts(['data' => [], 'meta' => ['next_cursor' => null]]);

    Native::test(ProductsScreen::class)->select('field-period', 'Past hour')->assertSee('No products match')->assertSee('Clear filters');
});

test('Show more appends the next page using the cursor and goes away at the end', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::sequence()
            ->push(['data' => [productRow(1)], 'meta' => ['next_cursor' => 'cur-1']])
            ->push(['data' => [productRow(2)], 'meta' => ['next_cursor' => null]]),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('Show more')
        ->tap('show-more')
        ->assertSee('Area Forecast Discussion 1')
        ->assertSee('Area Forecast Discussion 2')
        ->assertDontSee('Show more');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'limit' => '25', 'before' => 'cur-1']);
});

test('a search is saved as a recent search, tapping a recent search refills and re-runs it, and Clear empties the list', function () {
    fakeProducts();

    $screen = Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->select('field-office', 'MOB — Mobile, AL');

    expect(array_column((new LocalCache)->recentSearches(), 'wfo'))->toBe(['MOB', '']);

    Native::test(ProductsScreen::class)
        ->assertSee('Recent searches')
        ->assertSee('afd · MOB')
        ->tap('recent-0')
        ->assertSet('query', 'afd')
        ->assertSet('wfo', 'MOB')
        ->assertSee('Area Forecast Discussion 1');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'wfo' => 'MOB', 'limit' => '25']);

    Native::test(ProductsScreen::class)->tap('clear-recent')->assertDontSee('Recent searches');

    expect((new LocalCache)->recentSearches())->toBe([]);
});

test('offline it says so, keeps the recent searches and the typed text', function () {
    (new LocalCache)->rememberSearch(['q' => 'afd', 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '']);
    fakeProducts();
    $this->network->connected = false;

    Native::test(ProductsScreen::class)
        ->set('query', 'tornado')
        ->tap('search-products')
        ->assertSee("You're offline. Reconnect to search.")
        ->assertSee('afd')
        ->assertSet('query', 'tornado');
});

test('a failed search shows a message with Try again', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::response(['message' => 'boom'], 500),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('WxNotify could not search products. Try again in a moment.')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Try again');
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->forget();
    Native::test(ProductsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/*' => Http::response([], 401)]);
    Native::test(ProductsScreen::class)->assertReplacedWith('/sign-in');
});

test('the filter lists failing to load leaves the selects usable and the next search tries again', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::sequence()->push([], 500)->push(['data' => [['category' => 'x', 'label' => 'X', 'products' => [['id' => 6, 'name' => 'Winter Storm Warning']]]]]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::response(['data' => [productRow(1)], 'meta' => ['next_cursor' => null]]),
    ]);

    $screen = Native::test(ProductsScreen::class)->assertSet('types', []);

    $screen->submit('field-query', 'afd');

    expect($screen->get('types'))->toHaveCount(1);
});
```

In `tests/Feature/ScreensRenderTest.php` add (and `use App\NativeComponents\ProductsScreen;` at the top):

```php
test('the products screen renders its title, tabs, a text input, selects and a search button', function () {
    (new TokenStore)->put('9|secret');
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
    ]);

    Native::test(ProductsScreen::class)
        ->assertNavTitle('Products')
        ->assertHasTabBar()
        ->assertTabActive('Products')
        ->assertHasTab('Alerts')
        ->assertHasTab('Map')
        ->assertHasTab('Settings')
        ->assertElement('scroll_view')
        ->assertElement('outlined_text_input')
        ->assertElement('select')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Search');
});
```

- [ ] **Step 2: Run them and verify they fail** (`vendor/bin/pest --compact tests/Feature/ProductsScreenTest.php tests/Feature/ScreensRenderTest.php`).

- [ ] **Step 3: Implement**

Create `app/NativeComponents/ProductsScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\ProductSearchPresenter;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The Products tab: search the archive of weather products by name or ID, filter by product type, issuing
 * office and period, and open a result's public product page. Searches run on submit, never per keystroke.
 * Recent searches are kept on the phone; results and the filter lists are never cached.
 */
class ProductsScreen extends NativeComponent
{
    use RequiresSignIn;

    private const MIN_QUERY_LENGTH = 2;

    public string $query = '';

    public ?int $typeId = null;

    public string $wfo = '';

    public string $period = '';

    /** @var array<int, array{id: int, name: string}> */
    public array $types = [];

    /** @var array<int, array{code: string, label: string}> */
    public array $offices = [];

    /** @var array<int, array<string, mixed>>|null null until a search has run (the opening state) */
    public ?array $results = null;

    public ?string $nextCursor = null;

    /** @var array<int, array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}> */
    public array $recents = [];

    public bool $offline = false;

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->recents = app(LocalCache::class)->recentSearches();

        $this->loadFilters();
    }

    /**
     * The Search button and the keyboard's Search key (which passes the typed text).
     */
    public function search(?string $text = null): void
    {
        if ($text !== null) {
            $this->query = $text;
        }

        $this->runSearch();
    }

    public function pickType(string $label): void
    {
        $this->typeId = array_search($label, $this->typeOptions(), true) ?: null;

        $this->runSearch();
    }

    public function pickOffice(string $label): void
    {
        $this->wfo = (string) (collect($this->offices)->firstWhere('label', $label)['code'] ?? '');

        $this->runSearch();
    }

    public function pickPeriod(string $label): void
    {
        $this->period = (string) (array_search($label, ProductSearchPresenter::PERIODS, true) ?: '');

        $this->runSearch();
    }

    public function clearFilters(): void
    {
        $this->typeId = null;
        $this->wfo = '';
        $this->period = '';

        $this->runSearch();
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null || $this->results === null) {
            return;
        }

        try {
            $page = app(WxNotifyApi::class)->products($this->criteria(), $this->nextCursor);
        } catch (Unauthenticated) {
            $this->signOut();

            return;
        } catch (Offline) {
            $this->offline = true;

            return;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load more products. Try again in a moment.';

            return;
        }

        $this->results = [...$this->results, ...$page['data']];
        $this->nextCursor = $page['meta']['next_cursor'] ?? null;
        $this->offline = false;
        $this->error = null;
    }

    public function open(int $id): void
    {
        $url = collect($this->results)->firstWhere('id', $id)['view_url'] ?? null;

        if (filled($url)) {
            Browser::inApp($url);
        }
    }

    public function useRecent(int $index): void
    {
        $recent = $this->recents[$index] ?? null;

        if ($recent === null) {
            return;
        }

        $this->query = $recent['q'];
        $this->typeId = $recent['type'];
        $this->wfo = $recent['wfo'];
        $this->period = $recent['period'];

        $this->runSearch();
    }

    public function clearRecent(): void
    {
        app(LocalCache::class)->forgetSearches();

        $this->recents = [];
    }

    /**
     * @return array<int, string> the Type select's options: "Any type", then each catalog product (a name shared by two products gets its id)
     */
    public function typeLabels(): array
    {
        return ['Any type', ...array_values($this->typeOptions())];
    }

    public function selectedTypeLabel(): string
    {
        return $this->typeOptions()[$this->typeId] ?? 'Any type';
    }

    /**
     * @return array<int, string>
     */
    public function officeLabels(): array
    {
        return ['Any office', ...array_column($this->offices, 'label')];
    }

    public function selectedOfficeLabel(): string
    {
        return collect($this->offices)->firstWhere('code', $this->wfo)['label'] ?? 'Any office';
    }

    /**
     * @return array<int, string>
     */
    public function periodLabels(): array
    {
        return array_values(ProductSearchPresenter::PERIODS);
    }

    public function selectedPeriodLabel(): string
    {
        return ProductSearchPresenter::PERIODS[$this->period] ?? 'Any time';
    }

    public function hasFilters(): bool
    {
        return $this->typeId !== null || $this->wfo !== '' || $this->period !== '';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function subtitle(array $product): string
    {
        return ProductSearchPresenter::subtitle($product);
    }

    /**
     * @param  array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}  $search
     */
    public function recentLabel(array $search): string
    {
        return ProductSearchPresenter::recentLabel($search);
    }

    private function runSearch(): void
    {
        $text = trim($this->query);
        $this->message = null;

        if ($text !== '' && mb_strlen($text) < self::MIN_QUERY_LENGTH) {
            $this->message = 'Type at least '.self::MIN_QUERY_LENGTH.' characters to search.';
            $this->results = null;
            $this->nextCursor = null;

            return;
        }

        if ($text === '' && ! $this->hasFilters()) {
            $this->results = null;
            $this->nextCursor = null;
            $this->offline = false;
            $this->error = null;

            return;
        }

        if ($this->types === [] || $this->offices === []) {
            $this->loadFilters();
        }

        try {
            $page = app(WxNotifyApi::class)->products($this->criteria());
        } catch (Unauthenticated) {
            $this->signOut();

            return;
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;

            return;
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not search products. Try again in a moment.';

            return;
        }

        $this->results = $page['data'];
        $this->nextCursor = $page['meta']['next_cursor'] ?? null;
        $this->offline = false;
        $this->error = null;

        $cache = app(LocalCache::class);
        $cache->rememberSearch([
            'q' => $text,
            'type' => $this->typeId,
            'type_label' => $this->typeId === null ? null : ($this->typeOptions()[$this->typeId] ?? null),
            'wfo' => $this->wfo,
            'period' => $this->period,
        ]);
        $this->recents = $cache->recentSearches();
    }

    /**
     * @return array{q: ?string, type: ?int, wfo: ?string, period: ?string}
     */
    private function criteria(): array
    {
        return [
            'q' => trim($this->query) ?: null,
            'type' => $this->typeId,
            'wfo' => $this->wfo ?: null,
            'period' => $this->period ?: null,
        ];
    }

    /**
     * The filter lists. A failure leaves them empty (the selects still offer "Any"); the next search tries again.
     */
    private function loadFilters(): void
    {
        try {
            $api = app(WxNotifyApi::class);

            $this->types = collect($api->productTypes())->flatMap(fn (array $group): array => $group['products'])->values()->all();
            $this->offices = $api->nwsOffices();
        } catch (Unauthenticated) {
            $this->signOut();
        } catch (ApiException) {
            // Leave the lists as they are.
        }
    }

    /**
     * @return array<int, string> catalog product id => label
     */
    private function typeOptions(): array
    {
        $counts = array_count_values(array_column($this->types, 'name'));
        $options = [];

        foreach ($this->types as $type) {
            $options[$type['id']] = $counts[$type['name']] > 1 ? "{$type['name']} ({$type['id']})" : $type['name'];
        }

        return $options;
    }

    private function signOut(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
```

Create `resources/views/native/products-screen.blade.php`:

```blade
{{-- Un-prefixed on purpose: native:validate does not know <pressable>, <select> or <outlined-text-input>, nor @change on <select> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Products" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'products'])

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-3">
        <outlined-text-input label="Search products" placeholder="Name or ID, such as AFD or AFDMOB" native:model.blur="query" @submit="search" ref="field-query" />
        <native:button label="Search" @tap="search" ref="search-products" class="w-full" />

        <select label="Type" :options="$this->typeLabels()" :value="$this->selectedTypeLabel()" @change="pickType" ref="field-type" />
        <select label="Office" :options="$this->officeLabels()" :value="$this->selectedOfficeLabel()" @change="pickOffice" ref="field-office" />
        <select label="Period" :options="$this->periodLabels()" :value="$this->selectedPeriodLabel()" @change="pickPeriod" ref="field-period" />

        @if ($this->hasFilters())
            <pressable @tap="clearFilters" ref="clear-filters" class="w-full">
                <native:text class="text-sm font-semibold text-theme-primary p-2">Clear filters</native:text>
            </pressable>
        @endif

        @if ($offline)
            <native:text class="text-sm text-theme-on-surface-variant">You're offline. Reconnect to search.</native:text>
        @endif

        @if ($message)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $message }}</native:text>
        @endif

        @if ($error)
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="search" ref="retry-search" class="w-full" />
        @endif

        @if ($results === null)
            @if ($recents !== [])
                <native:row class="w-full justify-between items-center">
                    <native:text class="text-lg font-bold text-theme-on-background">Recent searches</native:text>
                    <pressable @tap="clearRecent" ref="clear-recent">
                        <native:text class="text-sm font-semibold text-theme-primary p-2">Clear</native:text>
                    </pressable>
                </native:row>

                @foreach ($recents as $index => $recent)
                    <pressable @tap="useRecent({{ $index }})" ref="recent-{{ $index }}" class="w-full">
                        <native:text class="text-base text-theme-on-background p-2">{{ $this->recentLabel($recent) }}</native:text>
                    </pressable>
                    <native:divider></native:divider>
                @endforeach
            @else
                <native:text class="text-sm text-theme-on-surface-variant">Search by name or ID, or pick a filter, to find a weather product.</native:text>
            @endif
        @else
            @forelse ($results as $product)
                @if (filled($product['view_url'] ?? null))
                    <pressable @tap="open({{ $product['id'] }})" ref="product-{{ $product['id'] }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $product['title'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($product) }}</native:text>
                        </native:column>
                    </pressable>
                @else
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $product['title'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($product) }}</native:text>
                    </native:column>
                @endif
                <native:divider></native:divider>
            @empty
                <native:column class="w-full p-6 items-center gap-2">
                    <native:text class="text-lg font-semibold text-theme-on-background">No products match</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant text-center">Try a different word or loosen a filter.</native:text>
                </native:column>
            @endforelse

            @if ($nextCursor !== null)
                <native:button label="Show more" variant="secondary" @tap="loadMore" ref="show-more" class="w-full" />
            @endif
        @endif
    </native:column>
</native:scroll-view>
```

In `resources/views/native/partials/tabs.blade.php`, between the Map and Settings items add:

```blade
    <native:bottom-nav-item id="products" label="Products" url="/products" icon="search" :ios-icon="Ios::DocTextMagnifyingglass" :android-icon="Android::ManageSearch" :active="$active === 'products'"></native:bottom-nav-item>
```

In `routes/mobile.php` add `use App\NativeComponents\ProductsScreen;` and `Route::native('/products', ProductsScreen::class);` (after `/map`).

- [ ] **Step 4: Run the new tests, then the whole app suite and `native:validate`**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/ProductsScreenTest.php tests/Feature/ScreensRenderTest.php` — expected PASS. Then `vendor/bin/pest --compact` (all green) and `php artisan native:validate` (clean). If a native element is rejected by `native:validate`, use the bare-tag form the other editors use; if a test's element assertion fails because `select` or `outlined_text_input` has a different prop name, read the sibling `LocationEditorTest` for the right assertion. Fix a failing test only if the spec's behavior is still met.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --format agent app/NativeComponents/ProductsScreen.php routes/mobile.php tests/Feature/ProductsScreenTest.php tests/Feature/ScreensRenderTest.php
git add -A . && git commit -m "App: Products tab to search the product archive, filter it and open a product

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Device check and notes (owner-assisted)

**Files:**
- Create: `<Claude project memory>/mobile-products-state.md` and its line in `MEMORY.md`.

- [ ] **Step 1:** Run the full backend suite in parallel in the app container (`docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`) and the full app suite; both must be green.

- [ ] **Step 2:** Ask the owner to deploy the backend to the dev host (push `main`; the controller does not push) and to rebuild the app with `native:run` on Android and iOS. Then drive both (adb or the iOS simulator tool) against the dev server and check: the Products tab sits between Map and Settings with a readable icon; a text search (`afd`); each filter alone and combined; the Type and Office selects at full length (if either is awkward on a device, report it as a finding for a pushed picker screen); Show more; opening a result (in-app browser) and coming back with the search still shown; recent searches, tapping one, Clear; one typed character; airplane mode; sign-out wipes the recents.

- [ ] **Step 3:** Write the memory note (what was built, the numbers measured in Task 1 Step 6, the device-check results and findings) and index it in `MEMORY.md`.

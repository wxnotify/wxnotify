# View Product Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public, unauthenticated page at `/view-product/{slug}` showing one ingested product's full text and metadata, for products from either feed (NWWS or EMWIN).

**Architecture:** A `view_slug` column on `ingested_products` (unique, indexed) is generated from the WMO header identity by a `GenerateViewSlug` action, set by a model `created` hook so both feed controllers and the test-product generator get it without changes, and backfilled for existing rows. A standalone Blade page (not Filament) is served by `ViewProductController` from `routes/public.php`, which is loaded outside the `web` middleware group so responses carry no session cookie and stay CDN-cacheable.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, PostgreSQL, Blade + Tailwind via Vite (`resources/css/app.css`).

**Spec:** `docs/superpowers/specs/2026-09-20-view-product-page-design.md`

## Global Constraints

- Slug format: `{cccc}-{ttaaii}-{awipsid}-{yyyymmddhhmm}[-{bbb}]`, lowercase, `[a-z0-9-]` only. Examples: `kewx-fous54-pfwewx-202609200625`; correction `kewx-fous54-pfwewx-202609200625-cca`; no AWIPS ID `kncf-wous99-202609200625`; no parseable header `nws-20260920062553-10122` (received time `YmdHis` + row id).
- The full timestamp comes from `received_at` plus the header's DDHHMM: same month, or the previous month when the header's day is later than the received day. An impossible date (e.g. day 31 in a 30-day month) means "no parseable header".
- On slug collision the row id is appended: `{slug}-{id}`.
- Product text is external content: always HTML-escaped (Blade `{{ }}`), never `{!! !!}`.
- Found: `Cache-Control: public, max-age=31536000, immutable`. Not found: `Cache-Control: public, max-age=60`. The route must not emit `Set-Cookie`.
- `is_test` product in production: 404. In other environments: "TEST PRODUCT" banner and `noindex`.
- Page shows the product's source (`source` column) as a label: `NWWS` or `EMWIN`.
- Optional dedicated host via `config('wxnotify.product_domain')` (`WXNOTIFY_PRODUCT_DOMAIN`); unset serves from the main domain.
- No new Composer dependencies. Leave `CLAUDE.md` unchanged (composer/boost hooks can rewrite it; you should not need composer).
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed`, and do NOT run `php artisan migrate` on the dev database (the controller does that after review). Tests use `RefreshDatabase`.
- Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`.
- Run `vendor/bin/pint --dirty --format agent` on the host before each commit. Commit messages end with the trailer line `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- Before editing, read `.ai/rules/index.md` and the rule files matching the paths you touch (`.ai/rules/models.md` for `app/Models/**`, `.ai/rules/feature.md` for `tests/Feature/**`). Style: curly braces always, explicit return types, constructor promotion.
- Tests that render the page must call `$this->withoutVite()` (the Vite manifest is not guaranteed in the test environment).

---

### Task 1: Slug column, generator and model hook

**Files:**
- Create: `database/migrations/2026_09_20_200001_add_view_slug_to_ingested_products_table.php`
- Create: `app/Actions/Pipeline/GenerateViewSlug.php`
- Modify: `app/Models/IngestedProduct.php` (add a `booted()` hook)
- Test: `tests/Feature/GenerateViewSlugTest.php`

**Interfaces:**
- Consumes: `App\Actions\Pipeline\ExtractWmoHeaderIdentity::__invoke(string $text): ?array` returning `['cccc','ttaaii','wmo_ddhhmm','bbb','awipsid','key']` (all lowercased; `bbb`/`awipsid` may be null).
- Produces (Tasks 2 and 3 rely on these exact names):
  - `App\Actions\Pipeline\GenerateViewSlug::__invoke(IngestedProduct $product): string`
  - `App\Actions\Pipeline\GenerateViewSlug::issuedAt(IngestedProduct $product): ?\Carbon\CarbonImmutable` — the header-derived UTC issue time, or `null` when the header day/time is unusable.
  - `ingested_products.view_slug` (nullable string, unique), set automatically on every newly created `IngestedProduct`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GenerateViewSlugTest.php`:

```php
<?php

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Carbon\CarbonImmutable;

function slugProduct(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kewx',
        'ttaaii' => 'fous54',
        'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625',
        'received_at' => '2026-09-20 06:25:53',
        'text' => "000\nSome product text\n",
        ...$attributes,
    ]);
}

test('a product gets a header-based slug when it is created', function () {
    expect(slugProduct()->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625');
});

test('a correction marker in the header is appended', function () {
    $product = slugProduct(['text' => "000\nFOUS54 KEWX 200625 CCA\nPFWEWX\nCorrected text\n"]);

    expect($product->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625-cca');
});

test('a product without an AWIPS id leaves that part out', function () {
    $product = slugProduct(['cccc' => 'kncf', 'ttaaii' => 'wous99', 'awipsid' => null]);

    expect($product->fresh()->view_slug)->toBe('kncf-wous99-202609200625');
});

test('a product without a parseable header falls back to received time and row id', function () {
    $product = slugProduct(['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null]);

    expect($product->fresh()->view_slug)->toBe("nws-20260920062553-{$product->id}");
});

test('a header day later than the received day belongs to the previous month', function () {
    $sameYear = slugProduct(['wmo_ddhhmm' => '302355', 'received_at' => '2026-10-01 00:02:10']);
    $acrossYear = slugProduct([
        'awipsid' => 'other',
        'wmo_ddhhmm' => '312355',
        'received_at' => '2027-01-01 00:01:00',
    ]);

    expect($sameYear->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609302355')
        ->and($acrossYear->fresh()->view_slug)->toBe('kewx-fous54-other-202612312355');
});

test('an impossible header date falls back instead of inventing one', function () {
    $product = slugProduct(['wmo_ddhhmm' => '312355', 'received_at' => '2026-10-01 00:02:10']);

    expect($product->fresh()->view_slug)->toBe("nws-20261001000210-{$product->id}");
});

test('a colliding slug gets the row id appended', function () {
    $first = slugProduct();
    $second = slugProduct(['file_path' => 'kewx/second-copy.txt']);

    expect($first->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625')
        ->and($second->fresh()->view_slug)->toBe("kewx-fous54-pfwewx-202609200625-{$second->id}");
});

test('an existing slug is never overwritten on create', function () {
    $product = IngestedProduct::factory()->create(['view_slug' => 'chosen-elsewhere']);

    expect($product->fresh()->view_slug)->toBe('chosen-elsewhere');
});

test('issuedAt returns the derived UTC time, or null when unusable', function () {
    $generator = app(GenerateViewSlug::class);

    expect($generator->issuedAt(slugProduct())?->equalTo(CarbonImmutable::parse('2026-09-20 06:25:00', 'UTC')))->toBeTrue()
        ->and($generator->issuedAt(slugProduct(['awipsid' => 'x', 'wmo_ddhhmm' => null])))->toBeNull()
        ->and($generator->issuedAt(slugProduct(['awipsid' => 'y', 'wmo_ddhhmm' => '202575'])))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/GenerateViewSlugTest.php --compact"`
Expected: FAIL (`view_slug` column / `GenerateViewSlug` class do not exist).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_20_200001_add_view_slug_to_ingested_products_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->string('view_slug')->nullable()->unique()->after('wmo_header_key');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->dropUnique(['view_slug']);
            $table->dropColumn('view_slug');
        });
    }
};
```

- [ ] **Step 4: Write the action**

Create `app/Actions/Pipeline/GenerateViewSlug.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Carbon\CarbonImmutable;

class GenerateViewSlug
{
    public function __construct(private readonly ExtractWmoHeaderIdentity $extractWmoHeaderIdentity) {}

    public function __invoke(IngestedProduct $product): string
    {
        $slug = $this->headerSlug($product) ?? $this->fallbackSlug($product);

        $taken = IngestedProduct::query()
            ->where('view_slug', $slug)
            ->whereKeyNot($product->getKey())
            ->exists();

        return $taken ? "{$slug}-{$product->getKey()}" : $slug;
    }

    /**
     * The header's DDHHMM carries no month or year, so the full UTC issue time
     * is placed relative to received_at: the received month, or the previous
     * month when the header's day is later than the received day. Null when
     * the header time is missing or not a real calendar date/time.
     */
    public function issuedAt(IngestedProduct $product): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', (string) $product->wmo_ddhhmm, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $hour = (int) $matches[2];
        $minute = (int) $matches[3];

        $month = $this->receivedAt($product)->startOfMonth();

        if ($day > $this->receivedAt($product)->day) {
            $month = $month->subMonthNoOverflow();
        }

        if ($hour > 23 || $minute > 59 || ! checkdate($month->month, $day, $month->year)) {
            return null;
        }

        return CarbonImmutable::create($month->year, $month->month, $day, $hour, $minute, 0, 'UTC');
    }

    private function headerSlug(IngestedProduct $product): ?string
    {
        if (blank($product->cccc) || blank($product->ttaaii)) {
            return null;
        }

        $issuedAt = $this->issuedAt($product);

        if ($issuedAt === null) {
            return null;
        }

        $bbb = ($this->extractWmoHeaderIdentity)((string) $product->text)['bbb'] ?? null;

        return $this->normalize(implode('-', array_filter([
            $product->cccc,
            $product->ttaaii,
            $product->awipsid,
            $issuedAt->format('YmdHi'),
            $bbb,
        ])));
    }

    private function fallbackSlug(IngestedProduct $product): string
    {
        return 'nws-'.$this->receivedAt($product)->format('YmdHis').'-'.$product->getKey();
    }

    private function receivedAt(IngestedProduct $product): CarbonImmutable
    {
        return CarbonImmutable::instance($product->received_at ?? now())->utc();
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
}
```

- [ ] **Step 5: Add the model hook**

In `app/Models/IngestedProduct.php` add `use App\Actions\Pipeline\GenerateViewSlug;` with the other imports, and add this method directly after the `casts()` method:

```php
    protected static function booted(): void
    {
        static::created(function (IngestedProduct $product): void {
            if ($product->view_slug === null) {
                $product->forceFill(['view_slug' => app(GenerateViewSlug::class)($product)])->saveQuietly();
            }
        });
    }
```

(`view_slug` is deliberately not in `#[Fillable]`; it is only ever written by this hook and the backfill.)

- [ ] **Step 6: Run the new test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/GenerateViewSlugTest.php --compact"`
Expected: PASS (9 tests).

- [ ] **Step 7: Confirm both feeds pick the hook up and nothing regressed**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
Expected: the whole suite passes (the hook adds one UPDATE per created product; nothing should depend on that). Report the total.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_20_200001_add_view_slug_to_ingested_products_table.php app/Actions/Pipeline/GenerateViewSlug.php app/Models/IngestedProduct.php tests/Feature/GenerateViewSlugTest.php
git commit -m "$(cat <<'EOF'
Generate a normalized view slug for every ingested product

Built from the WMO header identity so NWWS and EMWIN products share one URL scheme.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Backfill existing rows

**Files:**
- Create: `app/Actions/Pipeline/BackfillViewSlugs.php`
- Create: `database/migrations/2026_09_20_200002_backfill_view_slugs_on_ingested_products.php`
- Test: `tests/Feature/BackfillViewSlugsTest.php`

**Interfaces:**
- Consumes: `GenerateViewSlug::__invoke(IngestedProduct): string` and the `ingested_products.view_slug` column (Task 1).
- Produces: `App\Actions\Pipeline\BackfillViewSlugs::__invoke(): int` — sets a slug on every row whose `view_slug` is null, oldest id first (so the earliest product keeps the unsuffixed slug when two collide), and returns how many rows it updated.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackfillViewSlugsTest.php`:

```php
<?php

use App\Actions\Pipeline\BackfillViewSlugs;
use App\Models\IngestedProduct;
use Illuminate\Support\Facades\DB;

test('rows without a slug get one, oldest first, and existing slugs are untouched', function () {
    $attributes = [
        'cccc' => 'kewx', 'ttaaii' => 'fous54', 'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625', 'received_at' => '2026-09-20 06:25:53',
    ];
    $first = IngestedProduct::factory()->create($attributes);
    $second = IngestedProduct::factory()->create($attributes);
    $kept = IngestedProduct::factory()->create(['view_slug' => 'already-set']);

    DB::table('ingested_products')->whereIn('id', [$first->id, $second->id])->update(['view_slug' => null]);

    $updated = app(BackfillViewSlugs::class)();

    expect($updated)->toBe(2)
        ->and($first->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625')
        ->and($second->fresh()->view_slug)->toBe("kewx-fous54-pfwewx-202609200625-{$second->id}")
        ->and($kept->fresh()->view_slug)->toBe('already-set');
});

test('running the backfill again changes nothing', function () {
    IngestedProduct::factory()->count(3)->create();

    expect(app(BackfillViewSlugs::class)())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/BackfillViewSlugsTest.php --compact"`
Expected: FAIL (`BackfillViewSlugs` class not found).

- [ ] **Step 3: Write the action**

Create `app/Actions/Pipeline/BackfillViewSlugs.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;

class BackfillViewSlugs
{
    public function __construct(private readonly GenerateViewSlug $generateViewSlug) {}

    public function __invoke(): int
    {
        $updated = 0;

        IngestedProduct::query()
            ->whereNull('view_slug')
            ->chunkById(500, function ($products) use (&$updated): void {
                foreach ($products as $product) {
                    $product->forceFill(['view_slug' => ($this->generateViewSlug)($product)])->saveQuietly();
                    $updated++;
                }
            });

        return $updated;
    }
}
```

(`chunkById` walks ascending id, which gives the "oldest first" ordering the test relies on.)

- [ ] **Step 4: Write the data migration**

Create `database/migrations/2026_09_20_200002_backfill_view_slugs_on_ingested_products.php`:

```php
<?php

use App\Actions\Pipeline\BackfillViewSlugs;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillViewSlugs::class)();
    }

    public function down(): void
    {
        // Slugs are derived data; the column's own migration drops them.
    }
};
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/BackfillViewSlugsTest.php tests/Feature/GenerateViewSlugTest.php --compact"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Pipeline/BackfillViewSlugs.php database/migrations/2026_09_20_200002_backfill_view_slugs_on_ingested_products.php tests/Feature/BackfillViewSlugsTest.php
git commit -m "$(cat <<'EOF'
Backfill view slugs for existing ingested products

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The public View Product page

**Files:**
- Modify: `config/wxnotify.php` (add `product_domain`)
- Create: `routes/public.php`
- Modify: `bootstrap/app.php` (load `routes/public.php` outside the `web` group)
- Create: `app/Http/Controllers/ViewProductController.php`
- Modify: `app/Models/IngestedProduct.php` (add `viewUrl()`)
- Create: `resources/views/view-product/layout.blade.php`
- Create: `resources/views/view-product/show.blade.php`
- Create: `resources/views/view-product/not-found.blade.php`
- Test: `tests/Feature/ViewProductPageTest.php`

**Interfaces:**
- Consumes: `IngestedProduct::view_slug` (Task 1); `GenerateViewSlug::issuedAt(IngestedProduct): ?CarbonImmutable` (Task 1); `IngestedProduct::product()` BelongsTo `Product` (has `name`); columns `cccc`, `ttaaii`, `awipsid`, `wmo_ddhhmm`, `source`, `text`, `headline`, `is_test`, `received_at`, `vtec_action`, `vtec_office`, `vtec_phenomenon`, `vtec_significance`, `vtec_event_number`, `vtec_year`, `vtec_begin`, `vtec_end` (Carbon casts on the last two).
- Produces: named route `view-product.show` (`GET /view-product/{slug}`, `slug` matches `[a-z0-9-]+`); `IngestedProduct::viewUrl(): ?string` (absolute URL, `null` when the row has no slug) — later work (short links, alert links, the Active Alerts widget) builds links only through this method.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ViewProductPageTest.php`:

```php
<?php

use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutVite();
});

function pageProduct(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kewx',
        'ttaaii' => 'fous54',
        'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625',
        'received_at' => '2026-09-20 06:25:53',
        'source' => 'nwws',
        'text' => "FOUS54 KEWX 200625\nPFWEWX\nHello from the product text\n",
        ...$attributes,
    ])->fresh();
}

test('the page shows the product text and its metadata', function () {
    $catalog = Product::factory()->create(['name' => 'Fire Weather Point Forecast']);
    $product = pageProduct(['product_id' => $catalog->id, 'headline' => 'A headline for the page']);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('Fire Weather Point Forecast')
        ->assertSee('KEWX')
        ->assertSee('FOUS54 KEWX 200625')
        ->assertSee('PFWEWX')
        ->assertSee('2026-09-20 06:25 UTC')
        ->assertSee('NWWS')
        ->assertSee('A headline for the page')
        ->assertSee('Hello from the product text')
        ->assertSee('index,follow', false)
        ->assertSee($product->viewUrl(), false);
});

test('an EMWIN product is labelled with its source', function () {
    $product = pageProduct(['source' => 'emwin']);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('EMWIN')
        ->assertDontSee('NWWS');
});

test('VTEC details are shown when the product has them', function () {
    $product = pageProduct([
        'vtec_action' => 'NEW', 'vtec_office' => 'KEWX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
        'vtec_begin' => '2026-09-20 06:25:00', 'vtec_end' => '2026-09-20 07:00:00',
    ]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('NEW.KEWX.TO.W.0042')
        ->assertSee('2026-09-20 07:00 UTC');
});

test('the product text is escaped, never rendered as markup', function () {
    $product = pageProduct(['text' => "<script>alert(1)</script>\nplain line\n"]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('the page links to the dashboard login', function () {
    $product = pageProduct();

    $this->get(route('view-product.show', $product->view_slug))
        ->assertSee(rtrim(config('app.url'), '/').route('filament.dashboard.auth.login', absolute: false), false);
});

test('a found product is cacheable for a year with no cookie', function () {
    $product = pageProduct();

    $response = $this->get(route('view-product.show', $product->view_slug))->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('public')->toContain('max-age=31536000')->toContain('immutable');
    $response->assertHeaderMissing('Set-Cookie');
});

test('an unknown slug is a short-cached 404', function () {
    $response = $this->get('/view-product/does-not-exist')->assertNotFound();

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');
    $response->assertHeaderMissing('Set-Cookie');
});

test('a slug with disallowed characters never reaches the controller', function () {
    $this->get('/view-product/Not_A_Slug')->assertNotFound();
});

test('a test product is a 404 in production', function () {
    $product = pageProduct(['is_test' => true]);
    app()->instance('env', 'production');

    $this->get(route('view-product.show', $product->view_slug))->assertNotFound();
});

test('a test product outside production shows a banner and is not indexed', function () {
    $product = pageProduct(['is_test' => true]);

    $this->get(route('view-product.show', $product->view_slug))
        ->assertOk()
        ->assertSee('TEST PRODUCT')
        ->assertSee('noindex,nofollow', false)
        ->assertDontSee('index,follow', false);
});

test('a product without a slug has no view url', function () {
    $product = pageProduct();
    $product->forceFill(['view_slug' => null])->saveQuietly();

    expect($product->fresh()->viewUrl())->toBeNull();
});

test('the page is served only from the configured product domain', function () {
    $product = pageProduct();
    config(['wxnotify.product_domain' => 'products.example.test']);

    Route::setRoutes(new RouteCollection);
    require base_path('routes/public.php');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get("http://products.example.test/view-product/{$product->view_slug}")->assertOk();
    $this->get("http://other.example.test/view-product/{$product->view_slug}")->assertNotFound();

    expect($product->fresh()->viewUrl())->toBe("http://products.example.test/view-product/{$product->view_slug}");
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ViewProductPageTest.php --compact"`
Expected: FAIL (route `view-product.show` not defined).

- [ ] **Step 3: Add the config key**

In `config/wxnotify.php`, add after the `'mode'` line:

```php
    // Optional dedicated host for the public View Product pages (e.g. a
    // subdomain behind Cloudflare caching). Unset serves them from the main
    // domain, which is what local development and self-hosting use.
    'product_domain' => env('WXNOTIFY_PRODUCT_DOMAIN'),
```

- [ ] **Step 4: Add the route file and load it outside the `web` group**

Create `routes/public.php`:

```php
<?php

use App\Http\Controllers\ViewProductController;
use Illuminate\Support\Facades\Route;

// Loaded outside the web middleware group on purpose: no session or cookies,
// so these public, immutable pages stay cacheable at the CDN.
Route::group(array_filter(['domain' => config('wxnotify.product_domain')]), function (): void {
    Route::get('/view-product/{slug}', ViewProductController::class)
        ->where('slug', '[a-z0-9-]+')
        ->name('view-product.show');
});
```

In `bootstrap/app.php`, add a `then:` argument to `withRouting` (keep the existing arguments):

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/public.php';
        },
    )
```

- [ ] **Step 5: Add `viewUrl()` to the model**

In `app/Models/IngestedProduct.php`, add after the `scenario()` method:

```php
    public function viewUrl(): ?string
    {
        return $this->view_slug === null ? null : route('view-product.show', ['slug' => $this->view_slug]);
    }
```

- [ ] **Step 6: Write the controller**

Create `app/Http/Controllers/ViewProductController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;

class ViewProductController extends Controller
{
    private const NOT_FOUND_CACHE = 'public, max-age=60';

    private const FOUND_CACHE = 'public, max-age=31536000, immutable';

    public function __invoke(string $slug, GenerateViewSlug $generateViewSlug): Response
    {
        $product = IngestedProduct::with('product')->where('view_slug', $slug)->first();

        if ($product === null || ($product->is_test && app()->isProduction())) {
            return response()->view('view-product.not-found', [], 404)
                ->header('Cache-Control', self::NOT_FOUND_CACHE);
        }

        $issuedAt = $generateViewSlug->issuedAt($product) ?? $product->received_at;
        $title = $this->title($product);
        $office = filled($product->cccc) ? strtoupper($product->cccc) : null;

        return response()->view('view-product.show', [
            'product' => $product,
            'title' => $title,
            'office' => $office,
            'description' => trim("{$title}".($office ? " issued by {$office}" : '').($issuedAt ? ' on '.$this->format($issuedAt) : '')).'.',
            'details' => $this->details($product, $office, $issuedAt),
            'loginUrl' => rtrim((string) config('app.url'), '/').route('filament.dashboard.auth.login', absolute: false),
        ])->header('Cache-Control', self::FOUND_CACHE);
    }

    private function title(IngestedProduct $product): string
    {
        return $product->product?->name
            ?? match (true) {
                filled($product->awipsid) => strtoupper($product->awipsid),
                filled($product->ttaaii) => strtoupper($product->ttaaii),
                default => 'Weather product',
            };
    }

    /**
     * @return array<string, string>
     */
    private function details(IngestedProduct $product, ?string $office, ?CarbonInterface $issuedAt): array
    {
        $details = array_filter([
            'Office' => $office,
            'Issued' => $issuedAt ? $this->format($issuedAt) : null,
            'WMO header' => filled($product->ttaaii) && filled($product->cccc) && filled($product->wmo_ddhhmm)
                ? strtoupper("{$product->ttaaii} {$product->cccc} {$product->wmo_ddhhmm}")
                : null,
            'AWIPS ID' => filled($product->awipsid) ? strtoupper($product->awipsid) : null,
            'Source' => match ($product->source) {
                'nwws' => 'NWWS',
                'emwin' => 'EMWIN',
                default => strtoupper((string) $product->source),
            },
        ]);

        if (filled($product->vtec_phenomenon)) {
            $details['VTEC'] = strtoupper(implode('.', array_filter([
                $product->vtec_action,
                $product->vtec_office,
                $product->vtec_phenomenon,
                $product->vtec_significance,
                $product->vtec_event_number,
            ]))).($product->vtec_year ? " ({$product->vtec_year})" : '');
        }

        if ($product->vtec_begin !== null) {
            $details['Valid from'] = $this->format($product->vtec_begin);
        }

        if ($product->vtec_end !== null) {
            $details['Valid until'] = $this->format($product->vtec_end);
        }

        return $details;
    }

    private function format(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i').' UTC';
    }
}
```

- [ ] **Step 7: Write the views**

Create `resources/views/view-product/layout.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'WxNotify')</title>
        @yield('head')
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <a href="{{ config('app.url') }}" class="flex items-center gap-2 font-semibold">
                    <img src="{{ asset('images/logo.png') }}" alt="" class="h-8 w-8">
                    WxNotify
                </a>
                @isset($loginUrl)
                    <a href="{{ $loginUrl }}" class="text-sm font-medium text-blue-600 hover:underline">Log in</a>
                @endisset
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            @yield('content')
        </main>

        <footer class="mx-auto max-w-4xl px-4 pb-8 text-xs text-slate-500">
            Weather product text is published by the National Weather Service.
        </footer>
    </body>
</html>
```

Create `resources/views/view-product/show.blade.php`:

```blade
@extends('view-product.layout')

@section('title', $title.($office ? " · {$office}" : '').' · WxNotify')

@section('head')
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $product->is_test ? 'noindex,nofollow' : 'index,follow' }}">
    <link rel="canonical" href="{{ $product->viewUrl() }}">
@endsection

@section('content')
    @if ($product->is_test)
        <div class="mb-6 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            TEST PRODUCT — generated for testing, not a real weather product.
        </div>
    @endif

    <h1 class="text-2xl font-semibold">{{ $title }}</h1>

    @if (filled($product->headline))
        <p class="mt-2 text-slate-700">{{ $product->headline }}</p>
    @endif

    <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm sm:grid-cols-2">
        @foreach ($details as $label => $value)
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 font-medium">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <pre class="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ $product->text }}</pre>
@endsection
```

Create `resources/views/view-product/not-found.blade.php`:

```blade
@extends('view-product.layout')

@section('title', 'Product not found · WxNotify')

@section('head')
    <meta name="robots" content="noindex">
@endsection

@section('content')
    <h1 class="text-2xl font-semibold">Product not found</h1>
    <p class="mt-2 text-slate-700">We couldn't find a weather product at this address.</p>
@endsection
```

- [ ] **Step 8: Run the page tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ViewProductPageTest.php --compact"`
Expected: PASS (12 tests). If the domain test fails only because the 404 for the wrong host renders through an error view that needs a route the test removed, assert `->assertStatus(404)` on the exception instead of switching the approach; report what you changed.

- [ ] **Step 9: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
Expected: everything passes. Report the total.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/wxnotify.php routes/public.php bootstrap/app.php app/Http/Controllers/ViewProductController.php app/Models/IngestedProduct.php resources/views/view-product tests/Feature/ViewProductPageTest.php
git commit -m "$(cat <<'EOF'
Add the public View Product page

Cacheable, cookie-free page at /view-product/{slug} showing a product's text, metadata and source.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage**
- Slug format, month derivation, BBB, fallback, collision suffix, backfill, `created` hook → Tasks 1–2.
- Route name/path, optional domain, `viewUrl()` single builder → Task 3.
- Page contents (product name with AWIPS fallback, office, issued UTC, WMO header, AWIPS ID, **source label**, VTEC, headline, monospace text, login link, escaping, SEO title/description/canonical/robots) → Task 3 controller + views + tests.
- Caching (immutable year for found, 60 s for 404; no cookie) → Task 3 (route file loaded outside `web` group).
- Test products (404 in production; banner + noindex elsewhere) → Task 3.
- Tests listed in the spec (slug cases, month rollover, collision, backfill, 200 content incl. both source labels, 404, test rules, escaping, domain routing) → all present.
- Out of scope per spec (map, radar, shortener, links, sitemap, dedup fix) → deliberately absent.

**Placeholder scan:** none; every step carries its code and commands.

**Type consistency:** `GenerateViewSlug::__invoke(IngestedProduct): string` and `::issuedAt(IngestedProduct): ?CarbonImmutable` are used with these names in Tasks 2 and 3; `BackfillViewSlugs::__invoke(): int`; `IngestedProduct::viewUrl(): ?string`; route name `view-product.show` with parameter `slug` throughout.

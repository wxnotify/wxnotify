# Replay Ingested Products Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin replay already-ingested products through the alert system as test-flagged copies that reach only test accounts, from a "Replay" row action and bulk action on the Ingested Products table, in non-production environments only.

**Architecture:** A new `replay_of_id` column marks a replay copy. `ReplayIngestedProduct` re-ingests the original's text through `IngestProductText` as an `is_test` copy (null `wmo_header_key`); `IngestedProductObserver` sends any copy with `replay_of_id` straight to `MatchWeatherAlertRules`, skipping `SyncActiveWwa`, so `active_wwas` is untouched. The existing matcher already restricts `is_test` products to rules owned by `is_test_account` users.

**Tech Stack:** Laravel 13, Filament v5 (Table actions, Infolist), Pest, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-09-26-replay-ingested-products-design.md`

## Global Constraints

- Run PHP/tests inside Sail: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact <path>"`. Pint: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`. Run Pint before every commit.
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:seed`, and do NOT run `php artisan migrate` on the dev DB (tests use `RefreshDatabase`). The user runs the migration.
- No new Composer or npm dependencies. Do not edit `CLAUDE.md` (if a hook rewrites it, `git checkout CLAUDE.md`). Do not push.
- Commits end with the trailer `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Work on `main`; stage only the files each task names, never `git add -A`.
- Read `.ai/rules/index.md` and the rule files matching the paths you edit (`app/Filament/**` → filament.md, `app/Models/**` → models.md, `tests/Feature/**` → feature.md, `app/Support/**` → support.md). Use `search-docs` (Laravel Boost) for Filament API questions.
- Test-file top-level function names are global across the whole Pest suite: every helper you declare must have a unique name (prefix with `replay`).
- To flip the environment in a test use `app()->instance('env', 'production')`; `config(['app.env' => ...])` does NOT move `app()->environment()` (see `.ai/rules/feature.md`).
- Use `IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([...]))` for fixtures that must not trigger `IngestedProductObserver`. Note that it also suppresses the model's own `created` hook, so such rows have a null `view_slug`.
- The replay is only allowed when `! app()->environment('production')`, enforced in the action visibility and inside the action class itself.
- The replay copy: `is_test = true`, `source` copied from the original, `wmo_header_key = null` (only `IngestOncePerWmoHeader` reads `ingested_products.wmo_header_key`; a copy carrying it would make intake dedup drop a later real product), `replay_of_id` = original id, `test_product_scenario_id = null`, `file_path` = `replay/{original id}-{uuid}`. The text is replayed unchanged (no VTEC time re-stamping). A replay never writes `active_wwas`, `pipeline_deliveries`, the heartbeat cache or a heartbeat ping.
- Bulk action: replays selected products oldest first (`received_at` ascending, then `id`), at most 25 per run (more replays nothing and shows a notification), one failing product never stops the rest.
- Do not change `MatchWeatherAlertRules` (its `is_test` → `is_test_account` filter is the routing guarantee being relied on).

---

### Task 1: `replay_of_id` column, model relations and the observer branch

**Files:**
- Create: `database/migrations/2026_09_26_100001_add_replay_of_id_to_ingested_products_table.php` (create with `php artisan make:migration add_replay_of_id_to_ingested_products_table --table=ingested_products --no-interaction`, then rename/fill; keep the date-prefixed style of the sibling migrations)
- Modify: `app/Models/IngestedProduct.php`
- Modify: `app/Observers/IngestedProductObserver.php`
- Test: `tests/Feature/IngestedProductReplayObserverTest.php`

**Interfaces:**
- Produces: nullable `ingested_products.replay_of_id` (FK to `ingested_products.id`, `nullOnDelete`, indexed); `IngestedProduct::replayOf(): BelongsTo`, `IngestedProduct::replays(): HasMany`; `replay_of_id` is in `#[Fillable]`.
- Produces: `IngestedProductObserver::created()` behaviour — a product with `replay_of_id !== null` is passed to `MatchWeatherAlertRules` only (never `SyncActiveWwa`), regardless of VTEC fields.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/IngestedProductReplayObserverTest.php`:

```php
<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;

function replayVtecAttributes(array $overrides = []): array
{
    return [
        'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'ugc_codes' => ['NYZ069'],
        'is_test' => true,
        ...$overrides,
    ];
}

test('a replay copy of a VTEC product is matched without writing active_wwas', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    IngestedProduct::factory()->create(replayVtecAttributes(['replay_of_id' => $original->id]));

    $spy->shouldHaveReceived('__invoke')->once();
    expect(ActiveWwa::count())->toBe(0);
});

test('a VTEC product that is not a replay still writes active_wwas', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);

    IngestedProduct::factory()->create(replayVtecAttributes());

    expect(ActiveWwa::count())->toBe(1);
});

test('a replay copy of a non-VTEC product is matched once', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]);

    $spy->shouldHaveReceived('__invoke')->once();
});

test('replay relations link a copy to its original and deleting the original clears the link', function () {
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    $copy = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]));

    expect($copy->replayOf->is($original))->toBeTrue()
        ->and($original->replays->pluck('id')->all())->toBe([$copy->id]);

    $original->delete();

    expect($copy->fresh()->replay_of_id)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/IngestedProductReplayObserverTest.php`. Expected: FAIL (`replay_of_id` column / relations missing).

- [ ] **Step 3: Implement.** Migration:

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
            $table->foreignId('replay_of_id')->nullable()->index()->constrained('ingested_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replay_of_id');
        });
    }
};
```

In `IngestedProduct` add `'replay_of_id',` to the `#[Fillable([...])]` list (next to `'is_test', 'test_product_scenario_id',`), import `Illuminate\Database\Eloquent\Relations\HasMany` (BelongsTo is already imported), and add:

```php
    public function replayOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replay_of_id');
    }

    public function replays(): HasMany
    {
        return $this->hasMany(self::class, 'replay_of_id');
    }
```

`IngestedProductObserver::created()` becomes:

```php
    public function created(IngestedProduct $product): void
    {
        if ($product->replay_of_id !== null) {
            // A replay only exercises alert delivery, so it must not write active_wwas.
            app(MatchWeatherAlertRules::class)($product);

            return;
        }

        if ($product->vtec_office !== null) {
            app(SyncActiveWwa::class)($product);

            return;
        }

        app(MatchWeatherAlertRules::class)($product);
    }
```

- [ ] **Step 4: Run to verify it passes** — the new file, then `tests/Feature/SyncActiveWwaTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/IngestedProductResourceTest.php` (all unchanged and passing).

- [ ] **Step 5: Commit** (Pint first):

```bash
git add database/migrations/2026_09_26_100001_add_replay_of_id_to_ingested_products_table.php app/Models/IngestedProduct.php app/Observers/IngestedProductObserver.php tests/Feature/IngestedProductReplayObserverTest.php
git commit -m "Add replay_of_id to ingested products and skip active_wwas for replay copies

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `ReplayIngestedProduct` action

**Files:**
- Create: `app/Actions/Pipeline/ReplayIngestedProduct.php`
- Test: `tests/Feature/ReplayIngestedProductTest.php`

**Interfaces:**
- Consumes: `IngestProductText::__invoke(string $text, string $filePath, array $identity, bool $isCap = false, array $extra = []): ?IngestedProduct` (existing); `replay_of_id` column and the observer branch (Task 1).
- Produces: `App\Actions\Pipeline\ReplayIngestedProduct` with `public static function allowed(): bool` (true unless `app()->environment('production')`) and `__invoke(IngestedProduct $original): IngestedProduct` — returns the new copy; throws `RuntimeException` in production, or when the text cannot be re-ingested.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/ReplayIngestedProductTest.php`:

```php
<?php

use App\Actions\Pipeline\ReplayIngestedProduct;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function replayForecastDiscussion(array $overrides = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'ktwc', 'ttaaii' => 'fxus65', 'awipsid' => 'afdtwc', 'wmo_ddhhmm' => '221432',
        'text' => "FXUS65 KTWC 221432\nAFDTWC\n\nAREA FORECAST DISCUSSION\nNational Weather Service Tucson AZ",
        'source' => 'nwws',
        ...$overrides,
    ]));
}

function replayTornadoWarning(array $overrides = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'awipsid' => 'torbou', 'wmo_ddhhmm' => '191622',
        'text' => "WFUS53 KBOU 191622\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN\nTornado Warning",
        'source' => 'emwin',
        'wmo_header_key' => 'wfus53 kbou 191622 torbou',
        ...$overrides,
    ]));
}

function replayRule(User $user, array $attributes): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create($attributes);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    return $rule;
}

test('a replay creates a test copy of the original and leaves the original untouched', function () {
    $original = replayForecastDiscussion();

    $copy = app(ReplayIngestedProduct::class)($original);

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->is_test)->toBeTrue()
        ->and($copy->replay_of_id)->toBe($original->id)
        ->and($copy->text)->toBe($original->text)
        ->and($copy->cccc)->toBe('ktwc')
        ->and($copy->ttaaii)->toBe('fxus65')
        ->and($copy->awipsid)->toBe('afdtwc')
        ->and($copy->wmo_ddhhmm)->toBe('221432')
        ->and($copy->source)->toBe('nwws')
        ->and($copy->test_product_scenario_id)->toBeNull()
        ->and($copy->file_path)->toStartWith("replay/{$original->id}-")
        ->and($copy->view_slug)->not->toBeNull();

    $fresh = $original->fresh();
    expect($fresh->is_test)->toBeFalse()
        ->and($fresh->replay_of_id)->toBeNull()
        ->and(IngestedProduct::count())->toBe(2);
});

test('the copy never carries the original wmo header key so intake dedup only sees the original', function () {
    $original = replayTornadoWarning();

    $copy = app(ReplayIngestedProduct::class)($original);

    expect($copy->wmo_header_key)->toBeNull()
        ->and(IngestedProduct::where('wmo_header_key', 'wfus53 kbou 191622 torbou')->pluck('id')->all())->toBe([$original->id]);
});

test('a replayed non-vtec product is delivered only to test account rules', function () {
    Queue::fake();
    $realRule = replayRule(User::factory()->create(['is_test_account' => false]), ['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $testRule = replayRule(User::factory()->create(['is_test_account' => true]), ['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);

    $copy = app(ReplayIngestedProduct::class)(replayForecastDiscussion());

    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id && $job->ingestedProductId === $copy->id);
});

test('a replayed vtec product is delivered only to test account rules and never writes active_wwas', function () {
    Queue::fake();
    $tornado = Product::where('code', 'TO.W')->sole();
    $realRule = replayRule(User::factory()->create(['is_test_account' => false]), ['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $realRule->products()->attach($tornado);
    $testRule = replayRule(User::factory()->create(['is_test_account' => true]), ['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $testRule->products()->attach($tornado);

    $copy = app(ReplayIngestedProduct::class)(replayTornadoWarning());

    expect($copy->vtec_office)->not->toBeNull()
        ->and(ActiveWwa::count())->toBe(0);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id && $job->ingestedProductId === $copy->id);
});

test('a replay never touches an existing real active_wwas row', function () {
    $original = replayTornadoWarning();
    $real = ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'is_test' => false,
        'vtec_office' => 'KBOU', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0009', 'vtec_year' => 2026,
    ]);
    $before = $real->fresh()->only(['status', 'latest_ingested_product_id', 'updated_at']);

    app(ReplayIngestedProduct::class)($original);

    expect(ActiveWwa::count())->toBe(1)
        ->and($real->fresh()->only(['status', 'latest_ingested_product_id', 'updated_at']))->toEqual($before);
});

test('a replay refuses to run in production', function () {
    $original = replayForecastDiscussion();
    app()->instance('env', 'production');

    expect(ReplayIngestedProduct::allowed())->toBeFalse()
        ->and(fn () => app(ReplayIngestedProduct::class)($original))->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1);
});

test('replaying is allowed outside production', function () {
    expect(ReplayIngestedProduct::allowed())->toBeTrue();
});

test('a product whose text cannot be re-ingested throws instead of returning nothing', function () {
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['format' => 'cap', 'text' => 'this is not a cap document']));

    expect(fn () => app(ReplayIngestedProduct::class)($original))->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1);
});
```

Before writing the `ActiveWwa::factory()` fixture read `database/factories/ActiveWwaFactory.php` and the `active_wwas` columns (`vtecKeyFor()` in `app/Models/ActiveWwa.php`) and adjust the attributes so the row matches the KBOU TO.W event 0009 (2026) the tornado text carries; keep the assertions' intent (the real row is unchanged and still the only row).

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/ReplayIngestedProductTest.php`. Expected: FAIL (`ReplayIngestedProduct` class not found).

- [ ] **Step 3: Implement** `app/Actions/Pipeline/ReplayIngestedProduct.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Str;
use RuntimeException;

class ReplayIngestedProduct
{
    public function __construct(private readonly IngestProductText $ingestProductText) {}

    public static function allowed(): bool
    {
        return ! app()->environment('production');
    }

    public function __invoke(IngestedProduct $original): IngestedProduct
    {
        if (! self::allowed()) {
            throw new RuntimeException('Replaying products is disabled in production.');
        }

        $replay = ($this->ingestProductText)(
            $original->text,
            'replay/'.$original->id.'-'.Str::uuid(),
            [
                'cccc' => $original->cccc,
                'ttaaii' => $original->ttaaii,
                'awipsid' => $original->awipsid,
                'wmo_ddhhmm' => $original->wmo_ddhhmm,
                'nwws_id' => null,
            ],
            $original->format === 'cap',
            [
                'is_test' => true,
                'source' => $original->source,
                'wmo_header_key' => null,
                'replay_of_id' => $original->id,
                'test_product_scenario_id' => null,
            ],
        );

        if ($replay === null) {
            throw new RuntimeException("Product {$original->id} could not be re-ingested.");
        }

        return $replay;
    }
}
```

If a test fails because the installed parser needs something the fixtures lack (for example the tornado text not resolving `product_id` for the TO.W rule), fix the FIXTURE (more realistic text or explicit `product_id` on the original), not the action, and keep every assertion.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/ReplayIngestedProductTest.php tests/Feature/IngestedProductReplayObserverTest.php`. Expected: all pass.

- [ ] **Step 5: Commit** (Pint first; stage the action and its test; message "Add the ReplayIngestedProduct action" with the trailer).

---

### Task 3: "Replay" row and bulk actions, and the "Replay of" link

**Files:**
- Modify: `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php`
- Modify: `app/Filament/Admin/Resources/IngestedProducts/Schemas/IngestedProductInfolist.php`
- Test: `tests/Feature/IngestedProductResourceTest.php` (append)
- Modify: `.ai/rules/` via the `record-rule` tool (Step 6)

**Interfaces:**
- Consumes: `ReplayIngestedProduct::allowed(): bool` and `__invoke(IngestedProduct): IngestedProduct` (Task 2); `IngestedProduct::replay_of_id`.
- Produces: table row action `replay` and bulk action `replay` (both labelled "Replay", hidden in production, with a confirmation modal); an infolist entry "Replay of" linking to the original.

**Implementation guidance:** confirm the Filament v5 API with `search-docs` for: `Filament\Actions\BulkAction`, `BulkActionGroup` in `toolbarActions`, `->requiresConfirmation()` / `->modalDescription()`, `->deselectRecordsAfterCompletion()`, and the test helpers for a bulk action (`selectTableRecords([...])` and `TestAction::make('replay')->table()->bulk()`), adjusting the exact chain to what is documented while keeping each test's intent. The admin panel has no custom theme: use Filament components only.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/IngestedProductResourceTest.php` (add `use App\Actions\Pipeline\ReplayIngestedProduct;`, `use Filament\Actions\Testing\TestAction;` if missing; its `beforeEach` already sets the admin panel and a staff user):

```php
function replayCopyIds(): array
{
    return IngestedProduct::whereNotNull('replay_of_id')->orderBy('id')->pluck('replay_of_id')->all();
}

test('the replay row action creates a test copy and notifies', function () {
    $product = IngestedProduct::factory()->create(['cccc' => 'ktwc']);

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('replay')->table($product))
        ->assertNotified('Replayed 1 product to test accounts');

    expect(IngestedProduct::where('replay_of_id', $product->id)->where('is_test', true)->count())->toBe(1);
});

test('the replay actions are hidden in production and a forged call does nothing', function () {
    $product = IngestedProduct::factory()->create();
    app()->instance('env', 'production');

    livewire(ListIngestedProducts::class)
        ->assertActionHidden(TestAction::make('replay')->table($product))
        ->assertActionHidden(TestAction::make('replay')->table()->bulk());

    expect(ReplayIngestedProduct::allowed())->toBeFalse();

    livewire(ListIngestedProducts::class)->callAction(TestAction::make('replay')->table($product));

    expect(IngestedProduct::whereNotNull('replay_of_id')->count())->toBe(0);
});

test('the bulk replay action replays the selected products oldest first', function () {
    $newest = IngestedProduct::factory()->create(['received_at' => now()->subHour()]);
    $oldest = IngestedProduct::factory()->create(['received_at' => now()->subDays(3)]);
    $middle = IngestedProduct::factory()->create(['received_at' => now()->subDay()]);
    $untouched = IngestedProduct::factory()->create();

    livewire(ListIngestedProducts::class)
        ->selectTableRecords([$newest->id, $oldest->id, $middle->id])
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Replayed 3 products to test accounts');

    expect(replayCopyIds())->toBe([$oldest->id, $middle->id, $newest->id])
        ->and(IngestedProduct::where('replay_of_id', $untouched->id)->exists())->toBeFalse();
});

test('the bulk replay action refuses more than 25 products and replays nothing', function () {
    $products = IngestedProduct::factory()->count(26)->create();

    livewire(ListIngestedProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Select 25 or fewer products to replay');

    expect(IngestedProduct::whereNotNull('replay_of_id')->count())->toBe(0);
});

test('one product that cannot be replayed does not stop the rest', function () {
    $first = IngestedProduct::factory()->create(['received_at' => now()->subDays(2)]);
    $broken = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['format' => 'cap', 'text' => 'not a cap document', 'received_at' => now()->subDay()]));
    $last = IngestedProduct::factory()->create(['received_at' => now()->subHour()]);

    livewire(ListIngestedProducts::class)
        ->selectTableRecords([$first->id, $broken->id, $last->id])
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Replayed 2 products; 1 could not be replayed');

    expect(replayCopyIds())->toBe([$first->id, $last->id]);
});

test('a replay copy links back to its original on the view page and an ordinary product does not', function () {
    $original = IngestedProduct::factory()->create();
    $copy = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]));

    livewire(ViewIngestedProduct::class, ['record' => $copy->id])
        ->assertSee('Replay of')
        ->assertSee("Product #{$original->id}");

    livewire(ViewIngestedProduct::class, ['record' => $original->id])
        ->assertDontSee('Replay of');
});
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/IngestedProductResourceTest.php --filter="replay"`. Expected: FAIL (the actions do not exist).

- [ ] **Step 3: Implement.** In `IngestedProductsTable` add the imports (`App\Actions\Pipeline\ReplayIngestedProduct`, `Filament\Actions\Action`, `Filament\Actions\BulkAction`, `Filament\Actions\BulkActionGroup`, `Filament\Notifications\Notification`, `Illuminate\Support\Collection`, `Illuminate\Support\Str`, `Throwable`), then replace the `->recordActions([...])` block and add `->toolbarActions([...])`:

```php
            ->recordActions([
                ViewAction::make(),
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => ReplayIngestedProduct::allowed())
                    ->requiresConfirmation()
                    ->modalHeading('Replay this product?')
                    ->modalDescription(self::REPLAY_WARNING)
                    ->action(fn (IngestedProduct $record) => self::replay(collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('replay')
                        ->label('Replay')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (): bool => ReplayIngestedProduct::allowed())
                        ->requiresConfirmation()
                        ->modalHeading('Replay the selected products?')
                        ->modalDescription(self::REPLAY_WARNING)
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::replay($records)),
                ]),
            ]);
```

and add to the class:

```php
    private const REPLAY_LIMIT = 25;

    private const REPLAY_WARNING = 'This re-sends the product through the alert system as a test copy. Only test accounts receive it, and it creates duplicate alerts.';

    /**
     * @param  Collection<int, IngestedProduct>  $records
     */
    private static function replay(Collection $records): void
    {
        if ($records->count() > self::REPLAY_LIMIT) {
            Notification::make()
                ->title('Select '.self::REPLAY_LIMIT.' or fewer products to replay')
                ->warning()
                ->send();

            return;
        }

        $replayed = 0;
        $failed = 0;

        foreach ($records->sortBy([['received_at', 'asc'], ['id', 'asc']]) as $product) {
            try {
                app(ReplayIngestedProduct::class)($product);
                $replayed++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $products = Str::plural('product', $replayed);

        $notification = Notification::make();

        if ($failed === 0) {
            $notification->title("Replayed {$replayed} {$products} to test accounts")->success();
        } else {
            $notification->title("Replayed {$replayed} {$products}; {$failed} could not be replayed")->warning();
        }

        $notification->send();
    }
```

Keep the existing `->columns(...)`, `->filters(...)` and the rest of `configure()` unchanged. In `IngestedProductInfolist` add, after the `scenario` entry (import `App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource`):

```php
                TextEntry::make('replay_of')
                    ->label('Replay of')
                    ->state(fn (IngestedProduct $record): ?string => $record->replay_of_id !== null ? "Product #{$record->replay_of_id}" : null)
                    ->url(fn (IngestedProduct $record): ?string => $record->replay_of_id !== null ? IngestedProductResource::getUrl('view', ['record' => $record->replay_of_id]) : null)
                    ->visible(fn (IngestedProduct $record): bool => $record->replay_of_id !== null),
```

(confirm the resource registers a `view` page in `getPages()`; the existing `ViewIngestedProduct` page suggests it does).

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/IngestedProductResourceTest.php tests/Feature/ReplayIngestedProductTest.php tests/Feature/IngestedProductReplayObserverTest.php`. Expected: all pass, including the pre-existing resource tests (e.g. "there are no create, edit, or delete actions" still passes: `edit`/`delete` remain absent).

- [ ] **Step 5: Verify in the browser.** Use the Browser tools (`mcp__Claude_Browser__*`; load via ToolSearch if deferred). Log in through `http://localhost:8080/dashboard/login` with its "Admin (admin@host.com)" one-click button (never type credentials), open `http://localhost:8080/admin/ingested-products`, and check: the "Replay" row action and bulk action appear, the confirmation modal shows the warning text, a replay produces a notification and a new row under the "Test only" filter, and the copy's view page shows "Replay of". NOTE: the dev DB has not run this task's migration; if the page errors on the missing `replay_of_id` column, say so in your report instead of running the migration, and rely on the Livewire tests.

- [ ] **Step 6: Record the rule** with the `record-rule` tool: glob `app/Actions/Pipeline/**`, title "Product replay", note: "A replay copy (IngestedProduct.replay_of_id set) is created by ReplayIngestedProduct through IngestProductText with is_test=true and wmo_header_key=null: intake dedup (IngestOncePerWmoHeader) is the only reader of ingested_products.wmo_header_key and does not exclude test rows, so a copy carrying the key would make it drop a later real product. IngestedProductObserver sends any replay copy straight to MatchWeatherAlertRules and never to SyncActiveWwa, so replays never write active_wwas; routing to test accounts only relies on MatchWeatherAlertRules' is_test -> is_test_account filter, which must not be loosened. Replaying is refused in production inside the action itself, not just hidden in the UI." Afterwards run `git checkout .ai/rules/index.md` only if the tool reordered unrelated rows there (keep a genuinely new row for the new rule file if one is added); confirm no existing rule file lost content.

- [ ] **Step 7: Commit** (Pint first; stage the table, the infolist, the test file and the rule file(s) the tool created/changed; message "Add Replay row and bulk actions to the Ingested Products table" with the trailer).

---

## Self-Review Notes

- **Spec coverage:** `replay_of_id` column + relations + `nullOnDelete` → Task 1; replay action (production refusal, text/identity copy, `is_test`, `source`, null `wmo_header_key`, unique `file_path`, own `view_slug`, original untouched, CAP failure throws) → Task 2; observer skip of `SyncActiveWwa` and no `active_wwas` write (including an existing real row untouched) → Tasks 1–2; routing to test accounts only on both VTEC and non-VTEC paths → Task 2; row + bulk "Replay", confirmation text, hidden in production, forged call does nothing, oldest-first order, 25 cap, failures don't stop the rest, notifications → Task 3; "Replay of" link → Task 3; migration note (user runs `php artisan migrate`) is a Global Constraint; rule note → Task 3 Step 6.
- **Type consistency:** `ReplayIngestedProduct::allowed(): bool`, `__invoke(IngestedProduct): IngestedProduct`, `replay_of_id`, `replayOf()`/`replays()` and the action name `replay` are identical across tasks.

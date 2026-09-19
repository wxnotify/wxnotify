# Reset to Factory Defaults Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An owner-only admin page that wipes chosen groups (pipeline data, catalogs, subscriber data) behind re-authentication, a live-count preview, and typed confirmation, in every environment.

**Architecture:** `users.is_owner` gates a Filament page. Three single-purpose "wipe" actions (`WipePipelineData`, `ResetCatalogs`, `WipeSubscriberData`) plus a read-only `PreviewFactoryReset` are composed by an orchestrator (`ResetToFactoryDefaults`) that runs the selected ones in one DB transaction and writes one audit entry. A `PipelineStack` contract (default implementation cannot stop anything) drives a manual "I stopped the pipeline" acknowledgment. Catalog reset restores products from a committed snapshot file plus `ProductCatalogSeeder`, in place, so product ids and subscribers' alert-rule selections survive.

**Tech Stack:** Laravel 13, Filament v5 (Pages + Schemas forms), Pest + Livewire testing, PostgreSQL, spatie/laravel-activitylog.

**Spec:** `docs/superpowers/specs/2026-09-19-reset-to-factory-defaults-design.md`

**Standing rules for every task (read before starting):**
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed` against the dev DB. Tests run inside the Sail container against the separate `testing` database: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`.
- Read `.ai/rules/index.md` and every rule file matching the paths you edit (`tests/Feature/**`, `app/Filament/**`, `app/Models/**`). In tests, flip environment with `app()->instance('env', ...)`, never `config(['app.env' => ...])`. Pest helper function names are global: prefix any helper you define (`frXxx`).
- Run `vendor/bin/pint --dirty --format agent` before each commit. Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Filament traps in this repo: use `assertFormFieldVisible/Hidden`, not `assertFormFieldExists`, for visibility; never chain `->hidden()->visible()`.

---

## File Structure

- Create `database/migrations/2026_09_19_000001_add_is_owner_to_users_table.php`
- Modify `app/Models/User.php` (cast, activity log, `saving` hook, `canBeImpersonated` unchanged)
- Modify `app/Filament/Admin/Resources/Users/Schemas/UserForm.php`, `.../Tables/UsersTable.php`
- Create `app/Console/Commands/GrantOwner.php`
- Create `database/seeders/ChannelTypeSeeder.php`; modify `database/migrations/2026_08_30_005021_create_channel_types_table.php`, `database/seeders/DatabaseSeeder.php`
- Create `database/data/shipped_product_catalog.php` (generated snapshot)
- Create `app/Contracts/PipelineStack.php`, `app/Support/Pipeline/UnmanagedPipelineStack.php`; modify `app/Providers/AppServiceProvider.php`
- Create `app/Actions/Admin/FactoryReset/{PreviewFactoryReset,WipePipelineData,ResetCatalogs,WipeSubscriberData,FactoryResetConfirmation,VerifyOwnerReauthentication}.php`
- Create `app/Actions/Admin/ResetToFactoryDefaults.php`
- Create `app/Filament/Admin/Pages/ResetToFactoryDefaults.php`, `resources/views/filament/admin/pages/reset-to-factory-defaults.blade.php`, `resources/views/filament/admin/pages/factory-reset-preview.blade.php`
- Tests: `tests/Feature/OwnerRoleTest.php`, `ChannelTypeSeederTest.php`, `ShippedProductCatalogTest.php`, `FactoryResetActionsTest.php`, `ResetToFactoryDefaultsTest.php`, `ResetToFactoryDefaultsPageTest.php`

---

### Task 1: `is_owner` role

**Files:**
- Create: `database/migrations/2026_09_19_000001_add_is_owner_to_users_table.php`, `app/Console/Commands/GrantOwner.php`
- Modify: `app/Models/User.php`, `app/Filament/Admin/Resources/Users/Schemas/UserForm.php`, `app/Filament/Admin/Resources/Users/Tables/UsersTable.php`
- Test: `tests/Feature/OwnerRoleTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

test('an owner is always staff', function () {
    $owner = User::factory()->create(['is_owner' => true, 'is_staff' => false]);

    expect($owner->fresh()->is_staff)->toBeTrue()
        ->and($owner->fresh()->is_owner)->toBeTrue();
});

test('is_owner defaults to false', function () {
    expect(User::factory()->create()->fresh()->is_owner)->toBeFalse();
});

test('changing is_owner is written to the audit log', function () {
    $user = User::factory()->create(['is_staff' => true]);
    $user->update(['is_owner' => true]);

    expect(Activity::where('subject_id', $user->id)->latest('id')->first()->attribute_changes->toArray())
        ->toHaveKey('attributes.is_owner');
});

test('only an owner can change the owner toggle in the admin user form', function () {
    Filament::setCurrentPanel('admin');
    $target = User::factory()->create(['is_staff' => true]);

    $this->actingAs(User::factory()->create(['is_staff' => true]));
    livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertFormFieldDisabled('is_owner');

    $this->actingAs(User::factory()->create(['is_owner' => true]));
    livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertFormFieldEnabled('is_owner')
        ->fillForm(['is_owner' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_owner)->toBeTrue();
});

test('the grant-owner command promotes a user by email and refuses unknown emails', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('app:grant-owner', ['email' => 'boss@example.com'])->assertSuccessful();
    expect($user->fresh()->is_owner)->toBeTrue();

    $this->artisan('app:grant-owner', ['email' => 'nobody@example.com'])->assertFailed();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/OwnerRoleTest.php --compact"`
Expected: FAIL (unknown column `is_owner`).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
```

- [ ] **Step 4: User model** — in `app/Models/User.php`: add `'is_owner'` to the `logOnly([...])` list; add `'is_owner' => 'boolean',` to `casts()`; add this method (after `getActivitylogOptions`):

```php
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->is_owner && ! $user->is_staff) {
                $user->is_staff = true;
            }
        });
    }
```
(If `User` already defines `booted()`, merge the `saving` hook into it.)

- [ ] **Step 5: UserForm** — after `Toggle::make('is_staff'),` add:

```php
                Toggle::make('is_owner')
                    ->label('Owner')
                    ->helperText('Owners can run Reset to Factory Defaults. Implies staff.')
                    ->disabled(fn (): bool => ! auth()->user()?->is_owner),
```
**UsersTable** — after the `is_staff` `IconColumn` add `IconColumn::make('is_owner')->label('Owner')->boolean(),`.

- [ ] **Step 6: Command**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:command GrantOwner --no-interaction"
```
Replace the generated class body:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantOwner extends Command
{
    protected $signature = 'app:grant-owner {email : Email of an existing user to promote to owner}';

    protected $description = 'Promote an existing user to owner (and staff). The only way to create the first owner.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        $user->update(['is_owner' => true]);
        $this->info("{$user->email} is now an owner.");

        return self::SUCCESS;
    }
}
```
`update(['is_owner' => true])` works only if `is_owner` is mass-assignable: `User` uses `#[Fillable([...])]`, so add `'is_owner'` to that attribute list? NO — that would let any mass-assign escalate privileges. Instead use `$user->forceFill(['is_owner' => true])->save();` here, and in the tests use `forceFill(...)->save()` where they call `update`. Update Step 1's test `->update(['is_owner' => true])` to `->forceFill(['is_owner' => true])->save()`. (The `EditUser` form path sets attributes via Filament's own `fill`; if it silently drops `is_owner` because it is not fillable, set it in `EditUser::mutateFormDataBeforeSave` is NOT needed — Filament's `handleRecordUpdate` uses `$record->update($data)`; if the test in Step 1 fails for that reason, add a `handleRecordUpdate` override on `EditUser` that `forceFill`s `is_owner` from `$data` only when `auth()->user()->is_owner`.)

- [ ] **Step 7: Run tests** — same command as Step 2. Expected: PASS. Then `vendor/bin/pest tests/Feature/UserResourceTest.php --compact` still passes.

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/User.php app/Filament/Admin/Resources/Users app/Console/Commands/GrantOwner.php tests/Feature/OwnerRoleTest.php
git commit -m "Add the is_owner role and grant-owner command"
```

---

### Task 2: `ChannelTypeSeeder` (shared shipped channel-type source)

**Files:**
- Create: `database/seeders/ChannelTypeSeeder.php`
- Modify: `database/migrations/2026_08_30_005021_create_channel_types_table.php`, `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/ChannelTypeSeederTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Support\Facades\DB;

test('the seeder restores edited shipped channel types and leaves custom ones to the caller', function () {
    DB::table('channel_types')->where('slug', 'email')->update(['enabled' => false, 'min_tier' => 'pro', 'sort_order' => 99]);
    DB::table('channel_types')->where('slug', 'slack')->delete();

    (new ChannelTypeSeeder)->run();

    expect(DB::table('channel_types')->where('slug', 'email')->first())
        ->enabled->toBeTrue()->min_tier->toBe('free')->sort_order->toBe(1)
        ->and(DB::table('channel_types')->where('slug', 'slack')->exists())->toBeTrue()
        ->and(DB::table('channel_types')->count())->toBe(count(ChannelTypeSeeder::SHIPPED));
});

test('the migration and the seeder agree on the shipped set', function () {
    expect(DB::table('channel_types')->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(array_column(ChannelTypeSeeder::SHIPPED, 'slug'));
});
```

- [ ] **Step 2: Run** — Expected: FAIL (class not found).

- [ ] **Step 3: Seeder**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChannelTypeSeeder extends Seeder
{
    /**
     * The shipped channel-type catalog; shared by the create_channel_types
     * migration and the Reset to Factory Defaults catalog reset.
     *
     * @var list<array{slug: string, min_tier: string, sort_order: int, enabled: bool}>
     */
    public const SHIPPED = [
        ['slug' => 'email', 'min_tier' => 'free', 'sort_order' => 1, 'enabled' => true],
        ['slug' => 'ntfy', 'min_tier' => 'free', 'sort_order' => 2, 'enabled' => true],
        ['slug' => 'discord', 'min_tier' => 'free', 'sort_order' => 3, 'enabled' => true],
        ['slug' => 'slack', 'min_tier' => 'free', 'sort_order' => 4, 'enabled' => true],
        ['slug' => 'telegram', 'min_tier' => 'free', 'sort_order' => 5, 'enabled' => true],
    ];

    public function run(): void
    {
        foreach (self::SHIPPED as $row) {
            $exists = DB::table('channel_types')->where('slug', $row['slug'])->exists();

            if ($exists) {
                DB::table('channel_types')->where('slug', $row['slug'])->update($row + ['updated_at' => now()]);
            } else {
                DB::table('channel_types')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
```

- [ ] **Step 4: Migration** — in `up()`, replace the whole `DB::table('channel_types')->insert([...]);` block with `(new \Database\Seeders\ChannelTypeSeeder)->run();` and drop the now-unused `use Illuminate\Support\Facades\DB;`. In `DatabaseSeeder::run()` add `$this->call(ChannelTypeSeeder::class);` beside the others.

- [ ] **Step 5: Run tests** — Expected: PASS. Also `vendor/bin/pest tests/Feature --filter=Channel --compact` passes.

- [ ] **Step 6: Commit** — `git add database tests/Feature/ChannelTypeSeederTest.php && git commit -m "Extract the shipped channel types into a reusable seeder"`

---

### Task 3: Shipped product catalog snapshot + drift test

`ProductCatalogSeeder` only merges columns onto rows that the `create_products_table` migrations already inserted, so it alone cannot restore an admin-edited name/status/sort order. A committed snapshot of the fully seeded catalog (every column except id, timestamps, `description`, `example`) is the reset's source of truth; a drift test fails whenever the seeders change without the snapshot being regenerated.

**Files:**
- Create: `database/data/shipped_product_catalog.php` (generated), `tests/Feature/ShippedProductCatalogTest.php`

- [ ] **Step 1: Generate the snapshot with a throwaway test.** Create `tests/Feature/TmpGenerateSnapshotTest.php` (DO NOT COMMIT):

```php
<?php

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('generate snapshot', function () {
    (new ProductCatalogSeeder)->run();

    $rows = DB::table('products')->orderBy('code')->get()
        ->map(fn ($row) => collect((array) $row)->except(['id', 'description', 'example', 'created_at', 'updated_at'])->all())
        ->all();

    file_put_contents(
        database_path('data/shipped_product_catalog.php'),
        "<?php\n\n// Generated from a fully seeded catalog. Regenerate when ProductCatalogSeeder or product migrations change (see ShippedProductCatalogTest).\nreturn ".var_export($rows, true).";\n",
    );

    expect($rows)->not->toBeEmpty();
});
```
Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/TmpGenerateSnapshotTest.php --compact"` then `rm tests/Feature/TmpGenerateSnapshotTest.php`. Run `vendor/bin/pint --dirty --format agent`. Inspect: `head -40 database/data/shipped_product_catalog.php` and confirm ~150 rows and that JSON columns (`risk_level_options`, `damage_tag_options`) are raw strings or null.

- [ ] **Step 2: Permanent drift test**

```php
<?php

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('the committed shipped catalog snapshot matches a freshly seeded catalog', function () {
    (new ProductCatalogSeeder)->run();

    $live = DB::table('products')->orderBy('code')->get()
        ->map(fn ($row) => collect((array) $row)->except(['id', 'description', 'example', 'created_at', 'updated_at'])->all())
        ->all();

    expect($live)->toBe(require database_path('data/shipped_product_catalog.php'));
})->group('catalog-snapshot');
```
Run it. Expected: PASS. If a later seeder change makes it fail, regenerate the snapshot with the throwaway test from Step 1 (its failure message should be read as "snapshot is stale").

- [ ] **Step 3: Commit** — `git add database/data/shipped_product_catalog.php tests/Feature/ShippedProductCatalogTest.php && git commit -m "Add the shipped product catalog snapshot and a drift test"`

---

### Task 4: `PipelineStack` contract

**Files:**
- Create: `app/Contracts/PipelineStack.php`, `app/Support/Pipeline/UnmanagedPipelineStack.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: add to `tests/Feature/FactoryResetActionsTest.php` (created in Task 5; for now create it with this test)

- [ ] **Step 1: Failing test** (`tests/Feature/FactoryResetActionsTest.php`, new file):

```php
<?php

use App\Contracts\PipelineStack;
use App\Support\Pipeline\UnmanagedPipelineStack;

test('the default pipeline stack cannot stop services and refuses to pretend it did', function () {
    $stack = app(PipelineStack::class);

    expect($stack)->toBeInstanceOf(UnmanagedPipelineStack::class)
        ->and($stack->canStop())->toBeFalse()
        ->and(fn () => $stack->stop())->toThrow(LogicException::class);
});
```

- [ ] **Step 2: Implement**

```php
<?php

namespace App\Contracts;

interface PipelineStack
{
    /** Whether stop() can really halt the pipeline services. */
    public function canStop(): bool;

    /** Stops every pipeline service so nothing writes during a wipe. */
    public function stop(): void;
}
```
```php
<?php

namespace App\Support\Pipeline;

use App\Contracts\PipelineStack;
use LogicException;

/**
 * Placeholder until Downloader control can stop the whole Pipeline stack.
 * The factory-reset UI makes the admin acknowledge stopping services by hand.
 */
class UnmanagedPipelineStack implements PipelineStack
{
    public function canStop(): bool
    {
        return false;
    }

    public function stop(): void
    {
        throw new LogicException('The pipeline stack cannot be stopped from here; stop the services manually.');
    }
}
```
In `AppServiceProvider::register()` add `$this->app->bind(\App\Contracts\PipelineStack::class, \App\Support\Pipeline\UnmanagedPipelineStack::class);` (import the classes at the top instead of FQCNs).

- [ ] **Step 3: Run test — PASS. Commit** — `git commit -m "Add the PipelineStack contract with an unmanaged default"`

---

### Task 5: Preview + the three wipe actions

**Files:**
- Create: `app/Actions/Admin/FactoryReset/{PreviewFactoryReset,WipePipelineData,ResetCatalogs,WipeSubscriberData}.php`
- Test: `tests/Feature/FactoryResetActionsTest.php` (append)

Toggle keys used everywhere: `pipeline`, `catalogs`, `subscribers`.

- [ ] **Step 1: Failing tests** (append; helper names prefixed `fr`):

```php
use App\Actions\Admin\FactoryReset\PreviewFactoryReset;
use App\Actions\Admin\FactoryReset\ResetCatalogs;
use App\Actions\Admin\FactoryReset\WipePipelineData;
use App\Actions\Admin\FactoryReset\WipeSubscriberData;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\DatabaseNotification;

function frSeedPipelineData(): void
{
    $ingested = IngestedProduct::factory()->count(3)->create();
    ActiveWwa::factory()->create(['latest_ingested_product_id' => $ingested->first()->id]);
    DeliveryLog::factory()->count(2)->create();
    TestProductScenario::factory()->create();
}

test('wiping pipeline data empties exactly its tables, restarts ids, and keeps saved scenarios', function () {
    frSeedPipelineData();
    $user = User::factory()->create();
    Location::factory()->for($user)->create();

    $counts = app(WipePipelineData::class)();

    expect($counts)->toBe(['ingested_products' => 3, 'active_wwas' => 1, 'delivery_logs' => 2])
        ->and(IngestedProduct::count())->toBe(0)
        ->and(ActiveWwa::count())->toBe(0)
        ->and(DeliveryLog::count())->toBe(0)
        ->and(TestProductScenario::count())->toBe(1)
        ->and(Location::count())->toBe(1)
        ->and(IngestedProduct::factory()->create()->id)->toBe(1);
});

test('wiping subscriber data removes non-staff users and everything they own but never staff or owners', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $owner = User::factory()->create(['is_owner' => true]);
    $sub = User::factory()->create();
    $fixture = User::factory()->create(['is_test_account' => true]);
    foreach ([$sub, $fixture] as $u) {
        Location::factory()->for($u)->create();
        NotificationChannel::factory()->for($u)->create();
        AlertRule::factory()->for($u)->create();
        $u->notify(new App\Notifications\AlertRuleDisabledNotification('x'));
    }
    Location::factory()->for($staff)->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $sub->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);

    $counts = app(WipeSubscriberData::class)();

    expect($counts['users'])->toBe(2)
        ->and(User::pluck('id')->sort()->values()->all())->toBe(collect([$staff->id, $owner->id])->sort()->values()->all())
        ->and(Location::count())->toBe(1)
        ->and(NotificationChannel::count())->toBe(0)
        ->and(AlertRule::count())->toBe(0)
        ->and(DatabaseNotification::count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0);
});

test('resetting catalogs restores shipped products in place, keeps ids and rule links, and removes custom ones', function () {
    (new ProductCatalogSeeder)->run();
    $shipped = Product::where('code', 'TO.W')->sole();
    $shippedId = $shipped->id;
    $custom = Product::factory()->create(['code' => 'CUSTOM.X']);
    $rule = AlertRule::factory()->create();
    $rule->products()->attach([$shipped->id, $custom->id]);
    $shipped->update(['name' => 'Renamed', 'status' => 'retired', 'sort_order' => 999]);
    Product::where('code', 'SV.W')->delete();
    DB::table('channel_types')->where('slug', 'email')->update(['enabled' => false]);
    DB::table('channel_types')->insert(['slug' => 'custom', 'enabled' => true, 'min_tier' => 'free', 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()]);

    $counts = app(ResetCatalogs::class)();

    $live = DB::table('products')->orderBy('code')->get()
        ->map(fn ($r) => collect((array) $r)->except(['id', 'description', 'example', 'created_at', 'updated_at'])->all())->all();

    expect($live)->toBe(require database_path('data/shipped_product_catalog.php'))
        ->and(Product::where('code', 'TO.W')->sole()->id)->toBe($shippedId)
        ->and(Product::where('code', 'CUSTOM.X')->exists())->toBeFalse()
        ->and(Product::where('code', 'SV.W')->exists())->toBeTrue()
        ->and(Product::where('code', 'TO.W')->sole()->description)->not->toBeNull()
        ->and($rule->fresh()->products()->pluck('products.id')->all())->toBe([$shippedId])
        ->and(DB::table('channel_types')->where('slug', 'custom')->exists())->toBeFalse()
        ->and(DB::table('channel_types')->where('slug', 'email')->value('enabled'))->toBeTrue()
        ->and($counts['custom_products_removed'])->toBe(1);
});

test('the preview counts exactly what each selected toggle would delete and writes nothing', function () {
    frSeedPipelineData();
    (new ProductCatalogSeeder)->run();
    $custom = Product::factory()->create(['code' => 'CUSTOM.X']);
    AlertRule::factory()->create()->products()->attach($custom);
    $sub = User::factory()->create();
    Location::factory()->for($sub)->create();
    User::factory()->create(['is_staff' => true]);

    $preview = app(PreviewFactoryReset::class)(['pipeline' => true, 'catalogs' => true, 'subscribers' => true]);

    expect($preview['pipeline'])->toBe(['ingested_products' => 3, 'active_wwas' => 1, 'delivery_logs' => 2])
        ->and($preview['catalogs'])->toBe(['custom_products' => 1, 'alert_rules_affected' => 1, 'custom_channel_types' => 0])
        ->and($preview['subscribers']['users'])->toBe(1)
        ->and($preview['subscribers']['locations'])->toBe(1)
        ->and($preview['real_subscribers_exist'])->toBeTrue()
        ->and(app(PreviewFactoryReset::class)(['pipeline' => true]))->toHaveKey('pipeline')->not->toHaveKey('catalogs')
        ->and(IngestedProduct::count())->toBe(3);
});
```
(If `AlertRuleDisabledNotification`'s constructor differs, look at `app/Notifications/AlertRuleDisabledNotification.php` and adapt that one line — any database notification works.)

- [ ] **Step 2: Run** — Expected: FAIL (classes missing).

- [ ] **Step 3: Implement `WipePipelineData`**

```php
<?php

namespace App\Actions\Admin\FactoryReset;

use Illuminate\Support\Facades\DB;

class WipePipelineData
{
    /**
     * @return array{ingested_products: int, active_wwas: int, delivery_logs: int}
     */
    public function __invoke(): array
    {
        $counts = [
            'ingested_products' => DB::table('ingested_products')->count(),
            'active_wwas' => DB::table('active_wwas')->count(),
            'delivery_logs' => DB::table('delivery_logs')->count(),
        ];

        DB::statement('TRUNCATE TABLE ingested_products, active_wwas, delivery_logs RESTART IDENTITY');

        return $counts;
    }
}
```

- [ ] **Step 4: Implement `WipeSubscriberData`**

```php
<?php

namespace App\Actions\Admin\FactoryReset;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WipeSubscriberData
{
    /**
     * Deletes every non-staff user (owners are always staff). Locations, rules,
     * channels, templates, pivots and delivery logs cascade via foreign keys;
     * the polymorphic notifications table and sessions have none, so they are
     * cleared explicitly. Deliberately uses query-builder deletes (no model events).
     *
     * @return array{users: int, locations: int, alert_rules: int, channels: int}
     */
    public function __invoke(): array
    {
        $ids = fn (Builder $query) => $query->select('id')->from('users')->where('is_staff', false);

        $counts = [
            'users' => DB::table('users')->where('is_staff', false)->count(),
            'locations' => DB::table('locations')->whereIn('user_id', $ids)->count(),
            'alert_rules' => DB::table('alert_rules')->whereIn('user_id', $ids)->count(),
            'channels' => DB::table('notification_channels')->whereIn('user_id', $ids)->count(),
        ];

        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->delete();
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('users')->where('is_staff', false)->delete();

        return $counts;
    }
}
```

- [ ] **Step 5: Implement `ResetCatalogs`**

```php
<?php

namespace App\Actions\Admin\FactoryReset;

use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ResetCatalogs
{
    /**
     * Restores the shipped catalogs IN PLACE: shipped products keep their ids
     * (so subscribers' alert_rule_product rows survive), admin-added products
     * and channel types are deleted.
     *
     * @return array{custom_products_removed: int, custom_channel_types_removed: int}
     */
    public function __invoke(): array
    {
        /** @var list<array<string, mixed>> $shipped */
        $shipped = require database_path('data/shipped_product_catalog.php');
        $shippedCodes = array_column($shipped, 'code');

        $removedProducts = DB::table('products')->whereNotIn('code', $shippedCodes)->delete();

        // Pass 1: make sure every shipped row exists with its base columns so the
        // seeder can merge descriptions/examples onto them.
        foreach ($shipped as $row) {
            $this->restore($row);
        }

        (new ProductCatalogSeeder)->run();

        // Pass 2: the seeder reassigns sort_order on re-run; re-apply the snapshot.
        foreach ($shipped as $row) {
            $this->restore($row);
        }

        $shippedSlugs = array_column(ChannelTypeSeeder::SHIPPED, 'slug');
        $removedChannelTypes = DB::table('channel_types')->whereNotIn('slug', $shippedSlugs)->delete();
        (new ChannelTypeSeeder)->run();

        return [
            'custom_products_removed' => $removedProducts,
            'custom_channel_types_removed' => $removedChannelTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function restore(array $row): void
    {
        $query = DB::table('products')->where('code', $row['code']);

        if ($query->exists()) {
            $query->update(Arr::except($row, ['code']) + ['updated_at' => now()]);

            return;
        }

        DB::table('products')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
    }
}
```
Note: `ProductCatalogSeeder` deletes its `ORPHAN_CODES`; those are not in the snapshot, so pass 1/2 never resurrect them.

- [ ] **Step 6: Implement `PreviewFactoryReset`**

```php
<?php

namespace App\Actions\Admin\FactoryReset;

use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PreviewFactoryReset
{
    /**
     * Read-only live counts for the toggles that are on.
     *
     * @param  array{pipeline?: bool, catalogs?: bool, subscribers?: bool}  $toggles
     * @return array<string, mixed>
     */
    public function __invoke(array $toggles): array
    {
        $preview = [];

        if ($toggles['pipeline'] ?? false) {
            $preview['pipeline'] = [
                'ingested_products' => DB::table('ingested_products')->count(),
                'active_wwas' => DB::table('active_wwas')->count(),
                'delivery_logs' => DB::table('delivery_logs')->count(),
            ];
        }

        if ($toggles['catalogs'] ?? false) {
            $shippedCodes = array_column(require database_path('data/shipped_product_catalog.php'), 'code');
            $customProductIds = fn (Builder $query) => $query->select('id')->from('products')->whereNotIn('code', $shippedCodes);

            $preview['catalogs'] = [
                'custom_products' => DB::table('products')->whereNotIn('code', $shippedCodes)->count(),
                'alert_rules_affected' => DB::table('alert_rule_product')->whereIn('product_id', $customProductIds)->distinct()->count('alert_rule_id'),
                'custom_channel_types' => DB::table('channel_types')->whereNotIn('slug', array_column(ChannelTypeSeeder::SHIPPED, 'slug'))->count(),
            ];
        }

        $ids = fn (Builder $query) => $query->select('id')->from('users')->where('is_staff', false);

        if ($toggles['subscribers'] ?? false) {
            $preview['subscribers'] = [
                'users' => DB::table('users')->where('is_staff', false)->count(),
                'locations' => DB::table('locations')->whereIn('user_id', $ids)->count(),
                'alert_rules' => DB::table('alert_rules')->whereIn('user_id', $ids)->count(),
                'channels' => DB::table('notification_channels')->whereIn('user_id', $ids)->count(),
            ];
        }

        $preview['real_subscribers_exist'] = DB::table('users')->where('is_staff', false)->where('is_test_account', false)->exists();

        return $preview;
    }
}
```

- [ ] **Step 7: Run tests** — Expected: PASS. If `TRUNCATE` errors because another table references one of the three, add that table to the same `TRUNCATE` list (Postgres names it in the error) and add its count to the returned array and the test.

- [ ] **Step 8: Commit** — `git add app/Actions/Admin tests/Feature/FactoryResetActionsTest.php && git commit -m "Add the factory-reset wipe actions and live-count preview"`

---

### Task 6: Confirmation phrase + owner re-authentication

**Files:**
- Create: `app/Actions/Admin/FactoryReset/FactoryResetConfirmation.php`, `app/Actions/Admin/FactoryReset/VerifyOwnerReauthentication.php`
- Test: `tests/Feature/FactoryResetActionsTest.php` (append)

- [ ] **Step 1: Failing tests**

```php
use App\Actions\Admin\FactoryReset\FactoryResetConfirmation;
use App\Actions\Admin\FactoryReset\VerifyOwnerReauthentication;
use Illuminate\Support\Facades\Hash;

test('the confirmation phrase is RESET, or the instance domain when subscriber data is selected', function () {
    config(['app.url' => 'https://alerts.example.com']);

    expect(FactoryResetConfirmation::expectedPhrase(['pipeline' => true, 'catalogs' => true]))->toBe('RESET')
        ->and(FactoryResetConfirmation::expectedPhrase(['pipeline' => true, 'subscribers' => true]))->toBe('alerts.example.com')
        ->and(FactoryResetConfirmation::matches(['pipeline' => true], 'RESET'))->toBeTrue()
        ->and(FactoryResetConfirmation::matches(['pipeline' => true], 'reset'))->toBeFalse()
        ->and(FactoryResetConfirmation::matches(['subscribers' => true], 'RESET'))->toBeFalse()
        ->and(FactoryResetConfirmation::matches(['subscribers' => true], 'Alerts.Example.com'))->toBeTrue();
});

test('re-authentication needs the current password', function () {
    $owner = User::factory()->create(['is_owner' => true, 'password' => 'correct-horse']);

    expect(app(VerifyOwnerReauthentication::class)($owner, 'correct-horse', null))->toBeTrue()
        ->and(app(VerifyOwnerReauthentication::class)($owner, 'wrong', null))->toBeFalse();
});

test('re-authentication also needs a valid authenticator code when 2FA is enabled', function () {
    $owner = User::factory()->create(['is_owner' => true, 'password' => 'correct-horse']);
    $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->actingAs($owner);
    Filament\Facades\Filament::setCurrentPanel('admin');

    expect(app(VerifyOwnerReauthentication::class)($owner, 'correct-horse', null))->toBeFalse()
        ->and(app(VerifyOwnerReauthentication::class)($owner, 'correct-horse', '000000'))->toBeFalse();

    $code = (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    expect(app(VerifyOwnerReauthentication::class)($owner, 'correct-horse', $code))->toBeTrue();
});
```
(If `saveAppAuthenticationSecret` or the Google2FA class differ in this install, check `app/Models/User.php`'s `InteractsWithAppAuthentication` trait and `vendor/filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php` — it uses `pragmarx/google2fa`; adapt only the setup lines.)

- [ ] **Step 2: Implement**

```php
<?php

namespace App\Actions\Admin\FactoryReset;

class FactoryResetConfirmation
{
    /**
     * @param  array<string, mixed>  $toggles
     */
    public static function expectedPhrase(array $toggles): string
    {
        if ($toggles['subscribers'] ?? false) {
            return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        }

        return 'RESET';
    }

    /**
     * @param  array<string, mixed>  $toggles
     */
    public static function matches(array $toggles, string $typed): bool
    {
        $expected = self::expectedPhrase($toggles);
        $typed = trim($typed);

        return ($toggles['subscribers'] ?? false)
            ? $expected !== '' && strcasecmp($typed, $expected) === 0
            : $typed === $expected;
    }
}
```
```php
<?php

namespace App\Actions\Admin\FactoryReset;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Hash;

class VerifyOwnerReauthentication
{
    public function __invoke(User $owner, string $password, ?string $authenticatorCode): bool
    {
        if (! Hash::check($password, $owner->password)) {
            return false;
        }

        $appAuthentication = AppAuthentication::make();

        if (! $appAuthentication->isEnabled($owner)) {
            return true;
        }

        return filled($authenticatorCode) && $appAuthentication->verifyCode($authenticatorCode);
    }
}
```

- [ ] **Step 3: Run tests — PASS. Commit** — `git commit -m "Add factory-reset confirmation phrases and owner re-authentication"`

---

### Task 7: Orchestrator

**Files:**
- Create: `app/Actions/Admin/ResetToFactoryDefaults.php`
- Test: `tests/Feature/ResetToFactoryDefaultsTest.php`

- [ ] **Step 1: Failing tests**

```php
<?php

use App\Actions\Admin\ResetToFactoryDefaults;
use App\Contracts\PipelineStack;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->create(['is_owner' => true]);
});

test('it runs only the selected toggles and logs one audit entry that survives', function () {
    IngestedProduct::factory()->count(2)->create();
    $sub = User::factory()->create();
    Location::factory()->for($sub)->create();

    $result = app(ResetToFactoryDefaults::class)(['pipeline' => true, 'catalogs' => false, 'subscribers' => false], $this->owner, pipelineStopped: true);

    expect(IngestedProduct::count())->toBe(0)
        ->and(Location::count())->toBe(1)
        ->and($result)->toHaveKey('pipeline')->not->toHaveKey('subscribers');

    $entry = Activity::where('description', 'Reset to factory defaults')->sole();
    expect($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->properties['toggles'])->toBe(['pipeline'])
        ->and($entry->properties['deleted']['pipeline']['ingested_products'])->toBe(2);
});

test('the audit log is never wiped, even by every toggle together', function () {
    activity()->log('earlier entry');

    app(ResetToFactoryDefaults::class)(['pipeline' => true, 'catalogs' => true, 'subscribers' => true], $this->owner, pipelineStopped: true);

    expect(Activity::where('description', 'earlier entry')->exists())->toBeTrue()
        ->and(Activity::where('description', 'Reset to factory defaults')->count())->toBe(1);
});

test('nothing selected is rejected', function () {
    app(ResetToFactoryDefaults::class)(['pipeline' => false], $this->owner, pipelineStopped: true);
})->throws(InvalidArgumentException::class);

test('the pipeline toggle needs the stopped acknowledgment while services cannot be stopped', function () {
    IngestedProduct::factory()->create();

    expect(fn () => app(ResetToFactoryDefaults::class)(['pipeline' => true], $this->owner, pipelineStopped: false))
        ->toThrow(DomainException::class)
        ->and(IngestedProduct::count())->toBe(1);
});

test('a stack that can stop is stopped first and needs no acknowledgment', function () {
    $stack = new class implements PipelineStack
    {
        public bool $stopped = false;

        public function canStop(): bool
        {
            return true;
        }

        public function stop(): void
        {
            $this->stopped = true;
        }
    };
    app()->instance(PipelineStack::class, $stack);
    IngestedProduct::factory()->create();

    app(ResetToFactoryDefaults::class)(['pipeline' => true], $this->owner, pipelineStopped: false);

    expect($stack->stopped)->toBeTrue()->and(IngestedProduct::count())->toBe(0);
});

test('a failure in a later toggle rolls back the earlier ones', function () {
    IngestedProduct::factory()->create();
    app()->bind(WipeSubscriberData::class, fn () => new class extends WipeSubscriberData
    {
        public function __invoke(): array
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(ResetToFactoryDefaults::class)(['pipeline' => true, 'subscribers' => true], $this->owner, pipelineStopped: true))
        ->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1)
        ->and(Activity::where('description', 'Reset to factory defaults')->exists())->toBeFalse();
});
```
Add `use App\Actions\Admin\FactoryReset\WipeSubscriberData;` to the test file's imports. Postgres `TRUNCATE` is transactional, so the pipeline wipe rolls back too.

- [ ] **Step 2: Implement**

```php
<?php

namespace App\Actions\Admin;

use App\Actions\Admin\FactoryReset\ResetCatalogs;
use App\Actions\Admin\FactoryReset\WipePipelineData;
use App\Actions\Admin\FactoryReset\WipeSubscriberData;
use App\Contracts\PipelineStack;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResetToFactoryDefaults
{
    public function __construct(private readonly PipelineStack $pipelineStack) {}

    /**
     * @param  array{pipeline?: bool, catalogs?: bool, subscribers?: bool}  $toggles
     * @return array<string, array<string, int>>  deleted row counts per group
     */
    public function __invoke(array $toggles, User $by, bool $pipelineStopped): array
    {
        $selected = array_keys(array_filter($toggles));

        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one group to reset.');
        }

        $wipesPipeline = in_array('pipeline', $selected, true);

        if ($wipesPipeline && ! $this->pipelineStack->canStop() && ! $pipelineStopped) {
            throw new DomainException('Stop the pipeline services and confirm before wiping pipeline data.');
        }

        if ($wipesPipeline && $this->pipelineStack->canStop()) {
            $this->pipelineStack->stop();
        }

        $result = DB::transaction(function () use ($selected): array {
            $result = [];

            if (in_array('pipeline', $selected, true)) {
                $result['pipeline'] = app(WipePipelineData::class)();
            }
            if (in_array('catalogs', $selected, true)) {
                $result['catalogs'] = app(ResetCatalogs::class)();
            }
            if (in_array('subscribers', $selected, true)) {
                $result['subscribers'] = app(WipeSubscriberData::class)();
            }

            return $result;
        });

        activity()
            ->causedBy($by)
            ->withProperties(['toggles' => $selected, 'deleted' => $result])
            ->log('Reset to factory defaults');

        return $result;
    }
}
```

- [ ] **Step 3: Run tests — PASS. Commit** — `git commit -m "Add the transactional factory-reset orchestrator with audit logging"`

---

### Task 8: The Filament page

**Files:**
- Create: `app/Filament/Admin/Pages/ResetToFactoryDefaults.php`, `resources/views/filament/admin/pages/reset-to-factory-defaults.blade.php`, `resources/views/filament/admin/pages/factory-reset-preview.blade.php`
- Test: `tests/Feature/ResetToFactoryDefaultsPageTest.php`

Page behavior (from the spec): toggles (all off) + pipeline acknowledgment checkbox (only when the pipeline toggle is on and the stack cannot stop) → "Continue" action opens a re-auth modal (password, plus authenticator code when 2FA is on) → on success the server-side session flag `factory_reset.reauthenticated_at` is set (5-minute window) and the Confirm section appears with the preview and the typed-confirmation input plus a danger "Reset" action → running validates and calls the orchestrator.

- [ ] **Step 1: Failing tests**

```php
<?php

use App\Filament\Admin\Pages\ResetToFactoryDefaults;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->owner = User::factory()->create(['is_owner' => true, 'password' => 'correct-horse']);
    $this->actingAs($this->owner);
});

test('only owners can reach the page, over HTTP too', function () {
    $this->get('/admin/reset-to-factory-defaults')->assertOk();

    $this->actingAs(User::factory()->create(['is_staff' => true]));
    $this->get('/admin/reset-to-factory-defaults')->assertForbidden();

    $this->actingAs(User::factory()->create());
    $this->get('/admin/reset-to-factory-defaults')->assertForbidden();
});

test('the page is available in production', function () {
    app()->instance('env', 'production');

    expect(ResetToFactoryDefaults::canAccess())->toBeTrue();
});

test('every toggle starts off and the confirm section is hidden until re-authentication', function () {
    livewire(ResetToFactoryDefaults::class)
        ->assertFormSet(['pipeline' => false, 'catalogs' => false, 'subscribers' => false])
        ->assertFormFieldHidden('confirmation');
});

test('a wrong password fails re-authentication and leaves the confirm section hidden', function () {
    livewire(ResetToFactoryDefaults::class)
        ->fillForm(['pipeline' => true])
        ->callAction('reauth', ['password' => 'nope'])
        ->assertFormFieldHidden('confirmation');
});

test('the pipeline acknowledgment appears only while services cannot be stopped', function () {
    livewire(ResetToFactoryDefaults::class)
        ->assertFormFieldHidden('pipeline_stopped')
        ->fillForm(['pipeline' => true])
        ->assertFormFieldVisible('pipeline_stopped');
});

function frReauthenticate($component, array $toggles)
{
    return $component->fillForm($toggles)->callAction('reauth', ['password' => 'correct-horse']);
}

test('a full pipeline reset runs after re-authentication, the acknowledgment and the typed word', function () {
    IngestedProduct::factory()->count(2)->create();
    Location::factory()->create();

    $component = livewire(ResetToFactoryDefaults::class);
    frReauthenticate($component, ['pipeline' => true, 'pipeline_stopped' => true])
        ->assertFormFieldVisible('confirmation')
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(IngestedProduct::count())->toBe(0)
        ->and(Location::count())->toBe(1)
        ->and(Activity::where('description', 'Reset to factory defaults')->count())->toBe(1);
});

test('a wrong typed confirmation blocks the reset', function () {
    IngestedProduct::factory()->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->fillForm(['confirmation' => 'reset'])
        ->callAction('reset')
        ->assertHasFormErrors(['confirmation']);

    expect(IngestedProduct::count())->toBe(1);
});

test('subscriber data requires the instance domain, not RESET', function () {
    config(['app.url' => 'https://alerts.example.com']);
    $sub = User::factory()->create();

    $component = frReauthenticate(livewire(ResetToFactoryDefaults::class), ['subscribers' => true]);

    $component->fillForm(['confirmation' => 'RESET'])->callAction('reset')->assertHasFormErrors(['confirmation']);
    expect(User::whereKey($sub->id)->exists())->toBeTrue();

    $component->fillForm(['confirmation' => 'alerts.example.com'])->callAction('reset')->assertHasNoFormErrors();
    expect(User::whereKey($sub->id)->exists())->toBeFalse()
        ->and(User::whereKey($this->owner->id)->exists())->toBeTrue();
});

test('running the reset without re-authenticating does nothing', function () {
    IngestedProduct::factory()->create();

    livewire(ResetToFactoryDefaults::class)
        ->fillForm(['pipeline' => true, 'pipeline_stopped' => true, 'confirmation' => 'RESET'])
        ->callAction('reset');

    expect(IngestedProduct::count())->toBe(1);
});

test('the preview lists live counts for the selected toggles', function () {
    IngestedProduct::factory()->count(3)->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->assertSee('3 ingested products');
});

test('the pipeline acknowledgment is required', function () {
    IngestedProduct::factory()->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => false])
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset')
        ->assertHasFormErrors(['pipeline_stopped']);

    expect(IngestedProduct::count())->toBe(1);
});
```
Filament caveats the implementer will hit: an Action mounted with `callAction('reset')` runs its closure without form-validating the page form, so `runReset()` must call `$this->form->getState()` itself (which throws a `ValidationException` that Livewire turns into `assertHasFormErrors`). `callAction` on a page-level action requires the action method to be named `<name>Action()`.

- [ ] **Step 2: Implement the page**

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\FactoryReset\FactoryResetConfirmation;
use App\Actions\Admin\FactoryReset\PreviewFactoryReset;
use App\Actions\Admin\FactoryReset\VerifyOwnerReauthentication;
use App\Actions\Admin\ResetToFactoryDefaults as RunFactoryReset;
use App\Contracts\PipelineStack;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class ResetToFactoryDefaults extends Page implements HasForms
{
    use InteractsWithForms;

    private const REAUTH_SESSION_KEY = 'factory_reset.reauthenticated_at';

    private const REAUTH_WINDOW_SECONDS = 300;

    protected string $view = 'filament.admin.pages.reset-to-factory-defaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Reset to Factory Defaults';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_owner === true;
    }

    public function mount(): void
    {
        $this->form->fill(['pipeline' => false, 'catalogs' => false, 'subscribers' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('What to reset')
                    ->description('Every group is off by default. Staff and owner accounts and the audit log are never deleted.')
                    ->schema([
                        Toggle::make('pipeline')
                            ->label('Pipeline & dispatch data')
                            ->helperText('Deletes every ingested product (test-generated included), clears active WWA tracking and the delivery log. Archived text already stored in B2/S3 is left in place.')
                            ->live(),
                        Checkbox::make('pipeline_stopped')
                            ->label('I have stopped the pipeline services (Downloader, Alert Engine, GLM, Threshold evaluator, Traccar poller)')
                            ->accepted()
                            ->visible(fn (Get $get): bool => (bool) $get('pipeline') && ! app(PipelineStack::class)->canStop()),
                        Toggle::make('catalogs')
                            ->label('Catalogs')
                            ->helperText('Resets the Product and Notification channel catalogs to their shipped state. Admin-added products and channel types are deleted.')
                            ->live(),
                        Toggle::make('subscribers')
                            ->label('Subscriber data')
                            ->helperText('Permanently deletes every non-staff account with its locations, alert rules and channels. No grace period; unrecoverable.')
                            ->live(),
                    ]),
                Section::make('Confirm')
                    ->visible(fn (): bool => $this->isReauthenticated())
                    ->schema([
                        ViewField::make('preview')
                            ->label('')
                            ->view('filament.admin.pages.factory-reset-preview')
                            ->viewData(fn (): array => ['lines' => $this->previewLines()])
                            ->dehydrated(false),
                        TextInput::make('confirmation')
                            ->label(fn (): string => 'Type '.FactoryResetConfirmation::expectedPhrase($this->toggles()).' to confirm')
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! FactoryResetConfirmation::matches($this->toggles(), (string) $value)) {
                                    $fail('That does not match the confirmation text.');
                                }
                            })
                            ->visible(fn (): bool => $this->isReauthenticated()),
                    ]),
            ]);
    }

    /**
     * @return array{pipeline: bool, catalogs: bool, subscribers: bool}
     */
    protected function toggles(): array
    {
        return [
            'pipeline' => (bool) ($this->data['pipeline'] ?? false),
            'catalogs' => (bool) ($this->data['catalogs'] ?? false),
            'subscribers' => (bool) ($this->data['subscribers'] ?? false),
        ];
    }

    protected function isReauthenticated(): bool
    {
        $at = session(self::REAUTH_SESSION_KEY);

        return is_int($at) && (now()->timestamp - $at) <= self::REAUTH_WINDOW_SECONDS;
    }

    /**
     * @return list<string>
     */
    protected function previewLines(): array
    {
        $preview = app(PreviewFactoryReset::class)($this->toggles());
        $lines = [];

        if (isset($preview['pipeline'])) {
            $lines[] = number_format($preview['pipeline']['ingested_products']).' ingested products, '
                .number_format($preview['pipeline']['active_wwas']).' active WWAs, '
                .number_format($preview['pipeline']['delivery_logs']).' delivery log rows';

            if ($preview['real_subscribers_exist']) {
                $lines[] = 'Real subscribers exist: their activity and health widgets will read empty until new data arrives.';
            }
        }

        if (isset($preview['catalogs'])) {
            $lines[] = 'Catalogs restored to shipped state; '.$preview['catalogs']['custom_products'].' custom products removed (affecting '
                .$preview['catalogs']['alert_rules_affected'].' alert rules), '.$preview['catalogs']['custom_channel_types'].' custom channel types removed';
        }

        if (isset($preview['subscribers'])) {
            $lines[] = number_format($preview['subscribers']['users']).' subscribers, '
                .number_format($preview['subscribers']['locations']).' locations, '
                .number_format($preview['subscribers']['alert_rules']).' alert rules, '
                .number_format($preview['subscribers']['channels']).' channels';
        }

        return $lines ?: ['Nothing selected.'];
    }

    public function reauthAction(): Action
    {
        return Action::make('reauth')
            ->label('Continue')
            ->color('danger')
            ->modalHeading('Confirm it is you')
            ->modalDescription('Re-enter your password'.(AppAuthentication::make()->isEnabled(auth()->user()) ? ' and a current authenticator code' : '').' to continue.')
            ->schema([
                TextInput::make('password')->password()->required(),
                TextInput::make('authenticator_code')
                    ->label('Authenticator code')
                    ->visible(fn (): bool => AppAuthentication::make()->isEnabled(auth()->user()))
                    ->required(fn (): bool => AppAuthentication::make()->isEnabled(auth()->user())),
            ])
            ->action(function (array $data): void {
                $ok = app(VerifyOwnerReauthentication::class)(auth()->user(), (string) $data['password'], $data['authenticator_code'] ?? null);

                if (! $ok) {
                    session()->forget(self::REAUTH_SESSION_KEY);
                    Notification::make()->title('Re-authentication failed')->danger()->send();

                    return;
                }

                session([self::REAUTH_SESSION_KEY => now()->timestamp]);
            });
    }

    public function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset')
            ->color('danger')
            ->visible(fn (): bool => $this->isReauthenticated())
            ->action(fn () => $this->runReset());
    }

    public function runReset(): void
    {
        abort_unless(self::canAccess(), 403);

        if (! $this->isReauthenticated()) {
            Notification::make()->title('Re-authenticate first')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        try {
            $result = app(RunFactoryReset::class)($this->toggles(), auth()->user(), (bool) ($data['pipeline_stopped'] ?? false));
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Reset failed')->body($e->getMessage())->danger()->send();

            return;
        }

        session()->forget(self::REAUTH_SESSION_KEY);
        $this->form->fill(['pipeline' => false, 'catalogs' => false, 'subscribers' => false]);

        Notification::make()
            ->title('Reset complete')
            ->body(collect($result)->map(fn (array $counts, string $group): string => $group.': '.json_encode($counts))->implode('; '))
            ->success()
            ->send();
    }
}
```
If the `pipeline_stopped` `->accepted()` rule is skipped because the field is hidden while `pipeline` is off, that is correct; when `pipeline` is on and the stack cannot stop, the field is visible and `accepted()` blocks an unticked box (the test in Step 1 covers it).

- [ ] **Step 3: Views**

`resources/views/filament/admin/pages/reset-to-factory-defaults.blade.php`:
```blade
<x-filament-panels::page>
    <form wire:submit.prevent class="space-y-6">
        {{ $this->form }}
    </form>

    <div class="flex gap-3">
        @if (! (session('factory_reset.reauthenticated_at') && now()->timestamp - session('factory_reset.reauthenticated_at') <= 300))
            {{ $this->reauthAction }}
        @endif
        @if ($this->resetAction->isVisible())
            {{ $this->resetAction }}
        @endif
    </div>
</x-filament-panels::page>
```
`resources/views/filament/admin/pages/factory-reset-preview.blade.php` (inline styles for anything Tailwind might not compile in the un-themed admin panel):
```blade
<div class="rounded-lg border border-danger-500 p-4 text-sm" style="border-color: #dc2626;">
    <p style="font-weight: 600; margin-bottom: 0.5rem;">This will permanently delete:</p>
    <ul style="list-style: disc; padding-left: 1.25rem;">
        @foreach ($lines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</div>
```

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/ResetToFactoryDefaultsPageTest.php --compact`. Fix Filament API mismatches (action naming, `callAction` argument shapes) against `vendor/filament/**` and the existing `tests/Feature/PipelineControlTest.php` before changing test intent. The route path is derived from the class name (`reset-to-factory-defaults`); confirm with `php artisan route:list --path=admin/reset`.

- [ ] **Step 5: Commit** — `git add app/Filament/Admin/Pages resources/views/filament/admin/pages tests/Feature/ResetToFactoryDefaultsPageTest.php && git commit -m "Add the owner-only Reset to Factory Defaults page"`

---

### Task 9: Rules note and full-suite verification

- [ ] **Step 1:** Record the rule with the Boost `record-rule` tool: glob `app/Filament/Admin/Pages/**`, title "Reset to Factory Defaults is owner-only and never wipes staff or the audit log", note: "The page's canAccess() requires users.is_owner (no environment gate; it is intentionally available in production). WipeSubscriberData deletes only is_staff=false users; no toggle may touch activity_log. If a table gains a foreign key to ingested_products/active_wwas/delivery_logs, add it to WipePipelineData's TRUNCATE and count. The committed database/data/shipped_product_catalog.php snapshot must be regenerated when ProductCatalogSeeder or product migrations change (ShippedProductCatalogTest fails otherwise)." Commit `.ai`.
- [ ] **Step 2:** Run the full suite: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`. Expected: all pass (previous baseline 633 plus the new tests). Run `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 3:** Browser check (dev server via `preview_start` or the Sail site): log in as an owner (`php artisan app:grant-owner <email>` inside the container), open System → Reset to Factory Defaults, verify toggles, the Continue re-auth modal, the preview, the typed confirmation, and that a NON-owner staff user gets 403. Do not click Reset against the shared dev database.
- [ ] **Step 4:** Commit any fixes; report.

---

## Self-Review

**Spec coverage:** owner role (Task 1) · `PipelineStack` + manual acknowledgment (Tasks 4, 7, 8) · access gate, no env check, production allowed (Task 8 test) · Pipeline wipe: three tables, ids restarted, scenarios kept (Task 5) · Catalogs in place with snapshot, custom deleted, pivots intact, channel-types seeder (Tasks 2, 3, 5) · Subscribers: non-staff only, cascades, notifications/sessions cleared (Task 5) · re-auth password + TOTP (Task 6/8) · preview live counts + real-subscribers warning (Task 5/8) · RESET vs domain confirmation (Task 6/8) · single transaction, synchronous, all-or-nothing (Task 7) · single audit entry, audit log never touched (Task 7) · tests enumerated in spec (Tasks 1–8) · out-of-scope items untouched.

**Placeholder scan:** Task 1 Step 6 and Task 8 Step 2 flag Filament/Livewire API points to verify against vendor code rather than guess.

**Type consistency:** toggle keys `pipeline|catalogs|subscribers` everywhere; `WipePipelineData` returns `ingested_products|active_wwas|delivery_logs`; `WipeSubscriberData` returns `users|locations|alert_rules|channels`; `ResetCatalogs` returns `custom_products_removed|custom_channel_types_removed`; `PreviewFactoryReset` keys `pipeline|catalogs|subscribers|real_subscribers_exist`; orchestrator signature `(array $toggles, User $by, bool $pipelineStopped)` matches the page call.

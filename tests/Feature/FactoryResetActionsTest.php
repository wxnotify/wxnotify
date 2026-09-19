<?php

use App\Actions\Admin\FactoryReset\PreviewFactoryReset;
use App\Actions\Admin\FactoryReset\ResetCatalogs;
use App\Actions\Admin\FactoryReset\WipePipelineData;
use App\Actions\Admin\FactoryReset\WipeSubscriberData;
use App\Contracts\PipelineStack;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use App\Notifications\AlertRuleDisabledNotification;
use App\Support\Pipeline\UnmanagedPipelineStack;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

test('the default pipeline stack cannot stop services and refuses to pretend it did', function () {
    $stack = app(PipelineStack::class);

    expect($stack)->toBeInstanceOf(UnmanagedPipelineStack::class)
        ->and($stack->canStop())->toBeFalse()
        ->and(fn () => $stack->stop())->toThrow(LogicException::class);
});

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
        $rule = AlertRule::factory()->for($u)->create();
        $u->notify(new AlertRuleDisabledNotification(Product::factory()->create(), $rule));
    }
    Location::factory()->for($staff)->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $sub->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);

    $expectedRemoved = User::where('is_staff', false)->count();
    $counts = app(WipeSubscriberData::class)();

    expect($counts['users'])->toBe($expectedRemoved)
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

    $shippedCodes = array_column(require database_path('data/shipped_product_catalog.php'), 'code');
    $expectedUsers = User::where('is_staff', false)->count();
    $expectedLocations = Location::whereIn('user_id', User::where('is_staff', false)->select('id'))->count();
    $expectedCustom = Product::whereNotIn('code', $shippedCodes)->count();
    $preview = app(PreviewFactoryReset::class)(['pipeline' => true, 'catalogs' => true, 'subscribers' => true]);

    expect($preview['pipeline'])->toBe(['ingested_products' => 3, 'active_wwas' => 1, 'delivery_logs' => 2])
        ->and($preview['catalogs'])->toBe(['custom_products' => $expectedCustom, 'alert_rules_affected' => 1, 'custom_channel_types' => 0])
        ->and($preview['subscribers']['users'])->toBe($expectedUsers)
        ->and($preview['subscribers']['locations'])->toBe($expectedLocations)
        ->and($preview['real_subscribers_exist'])->toBeTrue()
        ->and(app(PreviewFactoryReset::class)(['pipeline' => true]))->toHaveKey('pipeline')->not->toHaveKey('catalogs')
        ->and(IngestedProduct::count())->toBe(3);
});

test('resetting catalogs restores a retired and renamed shipped product and refills blanked description and example', function () {
    (new ProductCatalogSeeder)->run();
    $product = Product::where('code', 'TO.W')->sole();
    $original = $product->only(['name', 'status', 'description', 'example']);
    $product->update(['name' => 'Renamed', 'status' => 'retired', 'description' => null, 'example' => null]);

    app(ResetCatalogs::class)();

    $restored = Product::where('code', 'TO.W')->sole();
    expect($restored->id)->toBe($product->id)
        ->and($restored->name)->toBe($original['name'])
        ->and($restored->status)->toBe($original['status'])
        ->and($restored->description)->not->toBeNull()
        ->and($restored->example)->not->toBeNull();
});

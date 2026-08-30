<?php

use App\Actions\Catalog\RetireProduct;
use App\Enums\ProductStatus;
use App\Filament\Admin\Resources\Products\Pages\CreateProduct;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\AlertRule;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can list products regardless of status', function () {
    $active = Product::factory()->create(['status' => ProductStatus::Active]);
    $retired = Product::factory()->retired()->create();

    livewire(ListProducts::class)
        ->searchTable($active->code)
        ->assertCanSeeTableRecords([$active])
        ->searchTable($retired->code)
        ->assertCanSeeTableRecords([$retired]);
});

test('staff can create a product', function () {
    livewire(CreateProduct::class)
        ->fillForm([
            'code' => 'XX.W',
            'name' => 'Test Warning',
            'family' => 'warning',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('code', 'XX.W')->exists())->toBeTrue();
});

test('staff can edit a product', function () {
    $product = Product::factory()->create(['name' => 'Old Name']);

    livewire(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('New Name');
});

test('retiring a product disables affected active alert rules and notifies their owners', function () {
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $owner = User::factory()->create();
    $rule = AlertRule::factory()->create(['user_id' => $owner->id, 'is_active' => true]);
    $rule->products()->attach($product->id);

    $inactiveOwner = User::factory()->create();
    $inactiveRule = AlertRule::factory()->create(['user_id' => $inactiveOwner->id, 'is_active' => false]);
    $inactiveRule->products()->attach($product->id);

    $count = app(RetireProduct::class)($product);

    expect($count)->toBe(1)
        ->and($product->fresh()->status)->toBe(ProductStatus::Retired)
        ->and($rule->fresh()->is_active)->toBeFalse()
        ->and($inactiveRule->fresh()->is_active)->toBeFalse();

    expect(DatabaseNotification::where('notifiable_id', $owner->id)->count())->toBe(1);
    expect(DatabaseNotification::where('notifiable_id', $inactiveOwner->id)->count())->toBe(0);
});

test('retiring a product with no affected rules does not error or create stray notifications', function () {
    $product = Product::factory()->create(['status' => ProductStatus::Active]);

    $count = app(RetireProduct::class)($product);

    expect($count)->toBe(0)
        ->and($product->fresh()->status)->toBe(ProductStatus::Retired)
        ->and(DatabaseNotification::count())->toBe(0);
});

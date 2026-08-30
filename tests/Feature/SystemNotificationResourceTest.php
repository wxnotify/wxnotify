<?php

use App\Actions\Catalog\RetireProduct;
use App\Filament\Admin\Resources\SystemNotifications\Pages\ListSystemNotifications;
use App\Models\AlertRule;
use App\Models\Product;
use App\Models\User;
use App\Notifications\AlertRuleDisabledNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('a seeded database notification is visible in the table', function () {
    $subscriber = User::factory()->create();
    $subscriber->notify(new AlertRuleDisabledNotification(
        Product::factory()->create(),
        AlertRule::factory()->for($subscriber)->create(),
    ));

    livewire(ListSystemNotifications::class)
        ->assertSee($subscriber->email);
});

test('the toggle-read action flips read_at in both directions', function () {
    $subscriber = User::factory()->create();
    $subscriber->notify(new AlertRuleDisabledNotification(
        Product::factory()->create(),
        AlertRule::factory()->for($subscriber)->create(),
    ));
    $notification = $subscriber->notifications()->sole();

    expect($notification->read())->toBeFalse();

    livewire(ListSystemNotifications::class)
        ->callTableAction('toggleRead', $notification);

    expect($notification->fresh()->read())->toBeTrue();

    livewire(ListSystemNotifications::class)
        ->callTableAction('toggleRead', $notification);

    expect($notification->fresh()->read())->toBeFalse();
});

test('delete removes the notification', function () {
    $subscriber = User::factory()->create();
    $subscriber->notify(new AlertRuleDisabledNotification(
        Product::factory()->create(),
        AlertRule::factory()->for($subscriber)->create(),
    ));
    $notification = $subscriber->notifications()->sole();

    livewire(ListSystemNotifications::class)
        ->callTableAction('delete', $notification);

    expect($subscriber->notifications()->count())->toBe(0);
});

test('retiring a product with an active alert rule produces a notification visible here', function () {
    Notification::fake();

    $product = Product::factory()->create();
    $subscriber = User::factory()->create();
    $rule = AlertRule::factory()->for($subscriber)->create(['is_active' => true]);
    $rule->products()->attach($product->id);

    app(RetireProduct::class)($product);

    Notification::assertSentTo($subscriber, AlertRuleDisabledNotification::class);
});

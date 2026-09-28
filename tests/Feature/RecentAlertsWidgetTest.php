<?php

use App\Filament\Dashboard\Widgets\RecentAlertsWidget;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->subscriber = User::factory()->create();
    $this->actingAs($this->subscriber);
});

test('shows only the current subscriber\'s own deliveries, not another subscriber\'s', function () {
    $mine = DeliveryLog::factory()->for($this->subscriber)->create();
    $someoneElses = DeliveryLog::factory()->for(User::factory())->create();

    livewire(RecentAlertsWidget::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('shows the rendered subject when present', function () {
    $log = DeliveryLog::factory()->for($this->subscriber)->create(['subject' => 'Tornado Warning for Pinal County']);

    livewire(RecentAlertsWidget::class)
        ->assertTableColumnStateSet('subject', 'Tornado Warning for Pinal County', $log);
});

test('falls back to the alert rule\'s displayName() when subject is null', function () {
    $rule = AlertRule::factory()->for($this->subscriber)->create();
    $log = DeliveryLog::factory()->for($this->subscriber)->create(['subject' => null, 'alert_rule_id' => $rule->id]);

    livewire(RecentAlertsWidget::class)
        ->assertTableColumnStateSet('subject', $rule->displayName(), $log);
});

test('a view action is offered only when the delivery is linked to a product', function () {
    // IngestedProduct auto-generates its own view_slug moments after creation
    // (see GenerateViewSlug) whenever one is missing, so "a product with no
    // view url" isn't a state that survives -- the real "no link" case is a
    // DeliveryLog with no ingested_product_id at all (Threshold Alerts/Daily
    // Weather Summary, or a "product gone" skip logged before one resolved).
    $withProduct = IngestedProduct::factory()->create();
    $logWithProduct = DeliveryLog::factory()->for($this->subscriber)->create(['ingested_product_id' => $withProduct->id]);
    $logWithoutProduct = DeliveryLog::factory()->for($this->subscriber)->create(['ingested_product_id' => null]);

    livewire(RecentAlertsWidget::class)
        ->assertTableActionVisible('viewProduct', $logWithProduct)
        ->assertTableActionHidden('viewProduct', $logWithoutProduct);
});

test('limits to the 8 most recent deliveries, ordered newest first', function () {
    $logs = collect(range(1, 10))->map(fn (int $i) => DeliveryLog::factory()->for($this->subscriber)->create([
        'dispatched_at' => now()->subMinutes(10 - $i),
    ]));

    $component = livewire(RecentAlertsWidget::class);
    $component->assertCanSeeTableRecords($logs->slice(2)->values(), inOrder: false);

    expect($component->instance()->getTableRecords())->toHaveCount(8)
        ->and($component->instance()->getTableRecords()->first()->id)->toBe($logs->last()->id);
});

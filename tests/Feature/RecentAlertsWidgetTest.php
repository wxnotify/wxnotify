<?php

use App\Filament\Dashboard\Widgets\RecentAlertsWidget;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;

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

/**
 * The mounted modal's heading and its content's HTML (Livewire's own HTML of a mounted action holds no modal).
 *
 * @return array{heading: string, html: string}
 */
function recentAlertsModal(Testable $component): array
{
    $action = $component->instance()->getMountedAction();

    return ['heading' => (string) $action->getModalHeading(), 'html' => (string) $action->getModalContent()->toHtml()];
}

test('a failure badge opens an explanation with why and what to do, and never the stored text', function () {
    $channel = NotificationChannel::factory()->for($this->subscriber)->create(['label' => 'Ops webhook']);
    $log = DeliveryLog::factory()->for($this->subscriber)->failed()->create([
        'notification_channel_id' => $channel->id,
        'error_detail' => 'Webhook delivery failed: the endpoint returned HTTP 404. https://hooks.example.com/SECRET-PATH',
    ]);

    $component = livewire(RecentAlertsWidget::class)
        ->assertTableActionVisible('explainDelivery', $log)
        ->mountTableAction('explainDelivery', $log);
    $modal = recentAlertsModal($component);

    expect($modal['heading'])->toBe('Why this delivery failed')
        ->and($modal['html'])->toContain('Ops webhook')
        ->toContain('Your endpoint refused the message.')
        ->toContain('What to do')
        ->toContain('Go to your channels')
        ->not->toContain('SECRET-PATH')
        ->not->toContain('hooks.example.com');
});

test('a failure only we can fix says so and offers no channels link', function () {
    $log = DeliveryLog::factory()->for($this->subscriber)->failed()->create(['error_detail' => 'Email delivery is turned off on this server.']);

    $modal = recentAlertsModal(livewire(RecentAlertsWidget::class)->mountTableAction('explainDelivery', $log));

    expect($modal['html'])->toContain('We&#039;re having a problem on our side.')
        ->not->toContain('Go to your channels');
});

test('an unrecognised failure shows the generic text and nothing from the stored text', function () {
    $log = DeliveryLog::factory()->for($this->subscriber)->failed()->create(['error_detail' => 'Provider said: token sk_live_SECRET rejected']);

    $modal = recentAlertsModal(livewire(RecentAlertsWidget::class)->mountTableAction('explainDelivery', $log));

    expect($modal['html'])->toContain('This delivery did not go through.')
        ->not->toContain('SECRET');
});

test('quiet hours and skipped rows can be explained too, and a success badge cannot be tapped', function () {
    $quiet = DeliveryLog::factory()->for($this->subscriber)->create(['status' => 'suppressed', 'error_detail' => null]);
    $skipped = DeliveryLog::factory()->for($this->subscriber)->create(['status' => 'skipped', 'error_detail' => 'The rule was deactivated after this delivery was dispatched.']);
    $sent = DeliveryLog::factory()->for($this->subscriber)->create(['status' => 'success']);

    $component = livewire(RecentAlertsWidget::class)
        ->assertTableActionVisible('explainDelivery', $quiet)
        ->assertTableActionVisible('explainDelivery', $skipped)
        ->assertTableActionHidden('explainDelivery', $sent)
        ->mountTableAction('explainDelivery', $quiet);
    $modal = recentAlertsModal($component);

    expect($modal['heading'])->toBe('Why this alert was held back')
        ->and($modal['html'])->toContain('Held back during your quiet hours.');
});

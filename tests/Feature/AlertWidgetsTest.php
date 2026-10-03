<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The stats the widget would render, as label => value. */
function widgetStats(object $component): array
{
    return collect((fn () => $this->getStats())->call($component->instance()))
        ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])
        ->all();
}

test('the stats strip shows active alerts, deliveries, failures and quiet-hours holds', function () {
    AlertRule::factory()->for($this->user)->count(2)->create(['is_active' => true]);
    AlertRule::factory()->for($this->user)->create(['is_active' => false]);
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'suppressed', 'dispatched_at' => now()->subHour()]);

    expect(widgetStats(livewire(AlertStatsWidget::class)))->toBe([
        'Active alerts' => '2 of 3',
        'Deliveries, last 7 days' => '3',
        'Failed deliveries, last 7 days' => '1',
        'Held for quiet hours, last 7 days' => '1',
    ]);
});

test('the failed-deliveries stat is colored danger above zero and success at zero', function () {
    $colorOf = fn () => (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Failed'))->getColor())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect($colorOf())->toBe('success');

    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);

    expect($colorOf())->toBe('danger');
});

test('the deliveries trend compares with the previous 7 days', function () {
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'success', 'dispatched_at' => now()->subDays(9)]);

    $description = (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Deliveries'))->getDescription())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect((string) $description)->toContain('2 more than the previous 7 days');
});

test('the stats strip appears on the My Alerts page, with data and without', function () {
    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);

    AlertRule::factory()->for($this->user)->create();

    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);
});

test('the stats widget does not poll and spans the full width', function () {
    $widget = livewire(AlertStatsWidget::class)->instance();

    expect((fn () => $this->getPollingInterval())->call($widget))->toBeNull()
        ->and((fn () => $this->columnSpan)->call($widget))->toBe('full');
});

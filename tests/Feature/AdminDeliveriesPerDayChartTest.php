<?php

use App\Filament\Admin\Widgets\AlertRulesByCategoryChart;
use App\Filament\Admin\Widgets\DeliveriesPerDayChart;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-11-15 18:00:00');

    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Chart data is built by a protected method; read it the way the widget's own view does.
 *
 * @return array<string, mixed>
 */
function deliveriesChartData(object $component): array
{
    return (fn () => $this->getData())->call($component->instance());
}

function logDelivery(string $status, string $utc = '2026-11-15 10:00:00', ?User $user = null): void
{
    DeliveryLog::factory()->for($user ?? User::factory()->create())->create(['status' => $status, 'dispatched_at' => $utc]);
}

/**
 * @param  array<string, mixed>  $data
 * @return array<int, int>
 */
function datasetFor(array $data, string $label): array
{
    return collect($data['datasets'])->firstWhere('label', $label)['data'];
}

test('the admin dashboard shows the deliveries chart', function () {
    $this->get('/admin')->assertOk()->assertSeeLivewire(DeliveriesPerDayChart::class);
});

test('it counts every subscriber\'s deliveries per day, split into sent, failed and skipped', function () {
    logDelivery('success');
    logDelivery('success');
    logDelivery('failure');
    logDelivery('skipped');
    logDelivery('success', '2026-11-14 09:00:00');

    $data = deliveriesChartData(livewire(DeliveriesPerDayChart::class));

    expect($data['labels'])->toHaveCount(14)
        ->and($data['labels'][13])->toBe('Nov 15')
        ->and(datasetFor($data, 'Sent')[13])->toBe(2)
        ->and(datasetFor($data, 'Sent')[12])->toBe(1)
        ->and(datasetFor($data, 'Failed')[13])->toBe(1)
        ->and(datasetFor($data, 'Skipped')[13])->toBe(1);
});

test('quiet days are plotted as zero and deliveries before the range are left out', function () {
    logDelivery('success', '2026-11-01 23:00:00');

    $data = deliveriesChartData(livewire(DeliveriesPerDayChart::class));

    expect(array_sum(datasetFor($data, 'Sent')))->toBe(0)
        ->and($data['labels'][0])->toBe('Nov 2');
});

test('days follow the signed-in staff member\'s timezone', function () {
    $this->staff->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    logDelivery('success', '2026-11-15 03:00:00');

    $data = deliveriesChartData(livewire(DeliveriesPerDayChart::class));

    expect(datasetFor($data, 'Sent')[12])->toBe(1)
        ->and(datasetFor($data, 'Sent')[13])->toBe(0);
});

test('the range filter offers 7, 14 and 30 days and falls back to 14', function () {
    expect(deliveriesChartData(livewire(DeliveriesPerDayChart::class)->set('filter', '7'))['labels'])->toHaveCount(7)
        ->and(deliveriesChartData(livewire(DeliveriesPerDayChart::class)->set('filter', '30'))['labels'])->toHaveCount(30)
        ->and(deliveriesChartData(livewire(DeliveriesPerDayChart::class)->set('filter', 'x'))['labels'])->toHaveCount(14);
});

test('it is sized like the alert rules chart beside it, so the two cards are the same height', function () {
    $maxHeight = fn (string $widget) => (fn () => $this->getMaxHeight())->call(app($widget));

    expect($maxHeight(DeliveriesPerDayChart::class))->toBe($maxHeight(AlertRulesByCategoryChart::class));
});

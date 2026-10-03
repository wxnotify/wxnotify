<?php

use App\Enums\AlertCategory;
use App\Filament\Dashboard\Widgets\AlertsByCategoryChart;
use App\Filament\Dashboard\Widgets\AlertsPerDayChart;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-02 18:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Chart data is built by a protected method; read it the way the widget's own view does.
 *
 * @return array<string, mixed>
 */
function chartData(object $component): array
{
    return (fn () => $this->getData())->call($component->instance());
}

function deliver(User $user, string $category, string $utc = '2026-10-02 10:00:00', string $status = 'success'): void
{
    DeliveryLog::factory()->for($user)->create(['dispatched_at' => $utc, 'category' => $category, 'status' => $status]);
}

test('the line chart defaults to 30 days and shows only this subscriber\'s successful deliveries per day', function () {
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'threshold');
    deliver($this->user, 'weather_alerts', status: 'failure');
    deliver(User::factory()->create(), 'weather_alerts');

    $data = chartData(livewire(AlertsPerDayChart::class));

    expect($data['labels'])->toHaveCount(30)
        ->and(end($data['labels']))->toBe('Oct 2')
        ->and($data['datasets'][0]['data'])->toHaveCount(30)
        ->and(end($data['datasets'][0]['data']))->toBe(2)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2);
});

test('the line chart range filter switches between 7, 30 and 90 days, and ignores junk', function () {
    $component = livewire(AlertsPerDayChart::class);

    // PHP turns the numeric-string option keys into ints; the dropdown submits them back as strings.
    expect(array_map('strval', array_keys((fn () => $this->getFilters())->call($component->instance()))))->toBe(['7', '30', '90']);

    foreach (['7' => 7, '90' => 90, '30' => 30, 'junk' => 30] as $filter => $expected) {
        $component->set('filter', $filter);

        expect(chartData($component)['labels'])->toHaveCount($expected);
    }
});

test('the line chart explains an empty period, and stays quiet when there is data', function () {
    $component = livewire(AlertsPerDayChart::class);

    expect($component->instance()->getDescription())->toBe('No alerts delivered in this period.');

    deliver($this->user, 'weather_alerts');

    expect(livewire(AlertsPerDayChart::class)->instance()->getDescription())->toBeNull();
});

test('the pie chart shows the category breakdown with a fixed color per category and Other last', function () {
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'weather_alerts');
    deliver($this->user, 'daily_summary');
    DeliveryLog::factory()->for($this->user)->create(['dispatched_at' => '2026-10-02 10:00:00', 'category' => null]);

    $data = chartData(livewire(AlertsByCategoryChart::class));

    expect($data['labels'])->toBe(['Weather Alerts', 'Daily Weather Summary', 'Other'])
        ->and($data['datasets'][0]['data'])->toBe([2, 1, 1])
        ->and($data['datasets'][0]['backgroundColor'])->toBe([
            AlertsByCategoryChart::COLORS['weather_alerts'],
            AlertsByCategoryChart::COLORS['daily_summary'],
            AlertsByCategoryChart::COLORS['other'],
        ]);
});

test('every alert category has its own distinct color', function () {
    $colors = AlertsByCategoryChart::COLORS;

    foreach (AlertCategory::cases() as $category) {
        expect($colors)->toHaveKey($category->value);
    }
    expect($colors)->toHaveKey('other')
        ->and(count(array_unique($colors)))->toBe(count($colors));
});

test('the pie chart range filter works and an empty period is explained', function () {
    expect(livewire(AlertsByCategoryChart::class)->instance()->getDescription())->toBe('No alerts delivered in this period.');

    deliver($this->user, 'weather_alerts', '2026-09-01 10:00:00'); // 31 days ago

    $thirty = livewire(AlertsByCategoryChart::class);
    expect(chartData($thirty)['labels'])->toBe([]);

    $ninety = livewire(AlertsByCategoryChart::class)->set('filter', '90');
    expect(chartData($ninety)['labels'])->toBe(['Weather Alerts'])
        ->and($ninety->instance()->getDescription())->toBeNull();
});

test('the charts do not poll', function () {
    foreach ([AlertsPerDayChart::class, AlertsByCategoryChart::class] as $widget) {
        expect((fn () => $this->getPollingInterval())->call(livewire($widget)->instance()))->toBeNull();
    }
});

test('both charts appear on the subscriber dashboard', function () {
    livewire(Dashboard::class)
        ->assertSeeLivewire(AlertsPerDayChart::class)
        ->assertSeeLivewire(AlertsByCategoryChart::class);
});

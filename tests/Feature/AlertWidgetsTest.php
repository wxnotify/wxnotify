<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\ActiveNowWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\NeedsAttentionWidget;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
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

/** The Failed stat as the widget builds it. */
function failedStat(): object
{
    return (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Failed')))
        ->call(livewire(AlertStatsWidget::class)->instance());
}

test('the failed-deliveries stat is colored danger above zero and success at zero, with a visible description and icon', function () {
    $stat = failedStat();

    expect($stat->getColor())->toBe('success')
        ->and((string) $stat->getDescription())->toBe('No failures')
        ->and($stat->getDescriptionIcon())->toBe('heroicon-m-check-circle')
        ->and($stat->getDescriptionColor())->toBe('success');

    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);

    $stat = failedStat();

    expect($stat->getColor())->toBe('danger')
        ->and((string) $stat->getDescription())->toBe('Across all channels, including ones that have recovered')
        ->and($stat->getDescriptionIcon())->toBe('heroicon-m-exclamation-triangle')
        ->and($stat->getDescriptionColor())->toBe('danger');
});

test('the stats widget is not lazy, so it never flashes an empty placeholder', function () {
    expect((fn () => static::isLazy())->bindTo(null, AlertStatsWidget::class)())->toBeFalse();
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

test('Needs attention shows an all-clear when nothing is wrong', function () {
    livewire(NeedsAttentionWidget::class)->assertSee('All your alerts look healthy');
});

test('Needs attention lists each problem with a link to the fix', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email', 'verified_at' => null]);
    $alert = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Morning summary']);
    $alert->notificationChannels()->attach($channel->id);
    $paused = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Storm warnings', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Work email')
        ->assertSee("isn't confirmed yet — alerts using it won't be sent until you confirm it")
        ->assertSee('Storm warnings')
        ->assertSee('is turned off')
        ->assertSeeHtml(route('filament.dashboard.resources.alert-rules.edit', ['record' => $paused->id]))
        ->assertSeeHtml(route('filament.dashboard.resources.notification-channels.index'));
});

test('Needs attention flags an active alert with no channel and links it to its edit page', function () {
    $alert = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Phone only']);

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Phone only')
        ->assertSee("has no notification channel, so it can't deliver — add a channel to it")
        ->assertSeeHtml('text-danger-600')
        ->assertSeeHtml(route('filament.dashboard.resources.alert-rules.edit', ['record' => $alert->id]));
});

test('Needs attention links a mobile alert with no position to its location', function () {
    $truck = Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);
    AlertRule::factory()->dailySummary()->for($this->user)->for($truck)->create(['name' => 'Truck summary'])
        ->notificationChannels()->attach(NotificationChannel::factory()->for($this->user)->create()->id);

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Truck summary')
        ->assertSee('is waiting for Truck to report a position')
        ->assertSeeHtml(route('filament.dashboard.resources.locations.edit', ['record' => $truck->id]));
});

test('Needs attention shows at most 8 rows and counts the rest', function () {
    foreach (range(1, 11) as $i) {
        AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => sprintf('Paused %02d', $i), 'is_active' => false]);
    }

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Paused 08')
        ->assertDontSee('Paused 09')
        ->assertSee('and 3 more');
});

test('Needs attention escapes subscriber-supplied names', function () {
    AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => '<script>alert(1)</script>', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>', escape: true);
});

test('Needs attention appears second on the My Alerts page', function () {
    livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class);
});

test('Active now invites a subscriber with no locations to add one', function () {
    livewire(ActiveNowWidget::class)->assertSee('Add a location to see active weather alerts here');
});

test('Active now says so when nothing is active at the subscriber\'s locations', function () {
    Location::factory()->for($this->user)->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);

    livewire(ActiveNowWidget::class)->assertSee('No active weather alerts at your locations');
});

test('Active now shows each warning with where it applies and whether an alert was sent', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)
        ->assertSee('Tornado Warning')
        ->assertSee('Home')
        ->assertSee('No alert sent for this warning')
        ->assertSeeHtml('wire:poll.60s');
});

test('Active now escapes subscriber-supplied location names', function () {
    Location::factory()->for($this->user)->create(['label' => '<script>alert(1)</script>', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>', escape: true);
});

test('all three widgets appear on the My Alerts page, stats first, then needs attention, then active now', function () {
    $page = livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class)
        ->assertSeeLivewire(ActiveNowWidget::class);

    expect((fn () => $this->getHeaderWidgets())->call($page->instance()))
        ->toBe([AlertStatsWidget::class, NeedsAttentionWidget::class, ActiveNowWidget::class]);

    $html = $page->html();
    $positions = collect([AlertStatsWidget::class, NeedsAttentionWidget::class, ActiveNowWidget::class])
        ->map(fn (string $widget): int|false => strpos($html, 'wire:name="'.$widget.'"'))
        ->all();

    expect($positions)->each->not->toBeFalse()
        ->and($positions[0])->toBeLessThan($positions[1])->and($positions[1])->toBeLessThan($positions[2]);
});

test('every tier can view the widgets', function () {
    foreach ([Tier::Free, Tier::Plus, Tier::Pro] as $tier) {
        $this->actingAs(User::factory()->create(['tier_override' => $tier]));

        expect(AlertStatsWidget::canView())->toBeTrue()
            ->and(NeedsAttentionWidget::canView())->toBeTrue()
            ->and(ActiveNowWidget::canView())->toBeTrue();
    }
});

test('Active now says a location without a position has nothing to check, distinct from nothing being active', function () {
    Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);

    livewire(ActiveNowWidget::class)
        ->assertSee('None of your locations has a position yet, so there\'s nothing to check.', escape: false)
        ->assertDontSee('No active weather alerts at your locations');
});

test('Active now words each status, with the day on times that are not today', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    // now() is 12:00 UTC = 6:00 AM Saturday in Denver.
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tomorrow warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHours(24)->addMinutes(15),
    ]);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Today warning', 'family' => 'watch'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHours(3),
    ]);

    $html = livewire(ActiveNowWidget::class)->html();

    expect($html)->toContain('until Sun 6:15 AM')->and($html)->toContain('until 9:00 AM')->not->toContain('until Sat')
        ->and($html)->toContain('"No alert sent" means none of your alerts delivered this warning. A warning issued before you created an alert may show this even though updates to it may reach you.');
});

test('Active now words alerted, held and failed events and shows the day of an alerted time that is not today', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    $rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);

    foreach (['success' => 'Alerted event', 'suppressed' => 'Held event', 'failure' => 'Failed event'] as $status => $name) {
        $wwa = ActiveWwa::factory()->create([
            'product_id' => Product::factory()->create(['name' => $name, 'family' => 'warning'])->id,
            'ugc_codes' => ['COZ040'], 'expires' => now()->addHours(2), 'created_at' => now()->subDays(2),
        ]);
        $ingested = IngestedProduct::factory()->createQuietly([
            'vtec_office' => $wwa->vtec_office, 'vtec_phenomenon' => $wwa->vtec_phenomenon, 'vtec_significance' => $wwa->vtec_significance,
            'vtec_event_number' => $wwa->vtec_event_number, 'vtec_year' => $wwa->vtec_year, 'is_test' => false,
        ]);
        DeliveryLog::factory()->for($this->user)->create([
            'ingested_product_id' => $ingested->id, 'alert_rule_id' => $rule->id, 'status' => $status,
            'dispatched_at' => $status === 'success' ? now()->subDay() : now()->subHour(),
        ]);
    }

    $html = livewire(ActiveNowWidget::class)->html();

    // The success was yesterday 6:00 AM in Denver, so its time carries the weekday.
    expect($html)->toContain('Alerted via Storm warnings')->and($html)->toContain('Fri 6:00 AM')
        ->and($html)->toContain('Not sent (quiet hours)')->and($html)->not->toContain('Held during quiet hours')
        ->and($html)->toContain('Delivery failed');
});

test('toggling an alert in the table tells the page\'s widgets to refresh', function () {
    $alert = AlertRule::factory()->for($this->user)->create(['is_active' => true]);

    livewire(ListAlertRules::class)
        ->call('updateTableColumnState', 'is_active', (string) $alert->id, false)
        ->assertDispatched('my-alerts-changed');

    expect($alert->fresh()->is_active)->toBeFalse();
});

test('deleting an alert, singly or in bulk, tells the page\'s widgets to refresh', function () {
    $alerts = AlertRule::factory()->for($this->user)->count(3)->create();

    livewire(ListAlertRules::class)
        ->callAction(TestAction::make('delete')->table($alerts[0]))
        ->assertDispatched('my-alerts-changed');

    livewire(ListAlertRules::class)
        ->selectTableRecords([$alerts[1]->id, $alerts[2]->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertDispatched('my-alerts-changed');

    expect(AlertRule::query()->whereBelongsTo($this->user)->count())->toBe(0);
});

test('Needs attention and the stats strip pick up changes when the page announces them', function () {
    $alert = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Storm warnings', 'is_active' => true]);
    $alert->notificationChannels()->attach(NotificationChannel::factory()->for($this->user)->create()->id);

    $needsAttention = livewire(NeedsAttentionWidget::class)->assertDontSee('Storm warnings');
    $stats = livewire(AlertStatsWidget::class);
    expect(widgetStats($stats)['Active alerts'])->toBe('1 of 1');

    $alert->update(['is_active' => false]);

    $needsAttention->dispatch('my-alerts-changed')->assertSee('Storm warnings');
    expect(widgetStats($stats->dispatch('my-alerts-changed')))->toHaveKey('Active alerts', '0 of 1');
});

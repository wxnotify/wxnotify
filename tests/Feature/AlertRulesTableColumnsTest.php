<?php

use App\Enums\MatchingMode;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A location-mode Weather Alerts rule listing the given locations and channels.
 *
 * @param  array<int, Location>  $locations
 * @param  array<int, NotificationChannel>  $channels
 */
function alertColumnsWeatherRule(User $user, array $locations = [], array $channels = [], string $mode = 'location'): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null,
    ]);
    $rule->locations()->attach(collect($locations)->pluck('id'));
    $rule->notificationChannels()->attach(collect($channels)->pluck('id'));

    return $rule;
}

/**
 * A Weather Alerts rule whose products each carry their own matching settings.
 *
 * @param  array<string, array{0: string, 1?: array<int, Location>}>  $perProduct  product name => [mode, locations]
 */
function alertColumnsPerProductRule(User $user, array $perProduct): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => null, 'location_id' => null,
    ]);

    foreach ($perProduct as $name => [$mode, $locations]) {
        $product = Product::factory()->create(['name' => $name]);
        $rule->products()->attach($product->id, ['matching_mode' => $mode, 'wfo' => null, 'county_zone_codes' => null]);

        foreach ($locations ?? [] as $location) {
            DB::table('alert_rule_product_location')->insert([
                'alert_rule_id' => $rule->id, 'product_id' => $product->id, 'location_id' => $location->id,
            ]);
        }
    }

    return $rule;
}

test('every matching mode has a short label', function () {
    foreach (MatchingMode::cases() as $mode) {
        expect($mode->shortLabel())->toBeString()->not->toBeEmpty();
    }

    expect(MatchingMode::Nationwide->shortLabel())->toBe('Nationwide')
        ->and(MatchingMode::Wfo->shortLabel())->toBe('Issuing office')
        ->and(MatchingMode::CountyZone->shortLabel())->toBe('County/zone');
});

test('a location-mode weather alert lists its locations in order', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $cabin = Location::factory()->for($this->user)->create(['label' => 'Cabin']);
    $rule = alertColumnsWeatherRule($this->user, [$home, $cabin]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home', 'Cabin'], $rule);
});

test('polygon and risk-area alerts list their saved locations too', function (string $mode) {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $rule = alertColumnsWeatherRule($this->user, [$home], mode: $mode);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home'], $rule);
})->with(['polygon', 'risk_area']);

test('scheduled alerts show their single location', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $daily = AlertRule::factory()->dailySummary()->for($this->user)->for($home)->create();
    $lightning = AlertRule::factory()->lightning()->for($this->user)->for($home)->create();
    $threshold = AlertRule::factory()->for($this->user)->for($home)->create();

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home'], $daily)
        ->assertTableColumnStateSet('location_labels', ['Home'], $lightning)
        ->assertTableColumnStateSet('location_labels', ['Home'], $threshold);
});

test('alerts not tied to a saved location show their matching mode', function (string $mode, string $label) {
    $rule = alertColumnsWeatherRule($this->user, mode: $mode);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', [$label], $rule);
})->with([
    'nationwide' => ['nationwide', 'Nationwide'],
    'wfo' => ['wfo', 'Issuing office'],
    'county/zone' => ['county_zone', 'County/zone'],
]);

test('an alert that should have locations but has none shows None', function () {
    $listless = alertColumnsWeatherRule($this->user, []);
    $orphaned = AlertRule::factory()->dailySummary()->for($this->user)->create(['location_id' => null]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['None'], $listless)
        ->assertTableColumnStateSet('location_labels', ['None'], $orphaned);
});

test('the channels column lists each attached channel, and shows None when there are none', function () {
    $discord = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    $email = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email']);
    $with = alertColumnsWeatherRule($this->user, [], [$discord, $email], mode: 'nationwide');
    $without = alertColumnsWeatherRule($this->user, [], [], mode: 'nationwide');

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('notificationChannels.label', ['My Discord', 'Work email'], $with)
        ->assertSeeHtml('fi-ta-placeholder'); // the placeholder rendered for the alert with no channels
    expect($without->notificationChannels)->toBeEmpty();
});

test('the new columns can be toggled and sit between Category and Active', function () {
    $component = livewire(ListAlertRules::class)
        ->assertTableColumnExists('location_labels')
        ->assertTableColumnExists('notificationChannels.label');

    $names = collect($component->instance()->getTable()->getColumns())->keys()->all();

    expect(array_search('category', $names, true))->toBeLessThan(array_search('location_labels', $names, true))
        ->and(array_search('location_labels', $names, true))->toBeLessThan(array_search('notificationChannels.label', $names, true))
        ->and(array_search('notificationChannels.label', $names, true))->toBeLessThan(array_search('is_active', $names, true));
});

test('another subscriber\'s alerts, locations and channels never appear', function () {
    $stranger = User::factory()->create();
    $theirLocation = Location::factory()->for($stranger)->create(['label' => 'Their Place']);
    $theirChannel = NotificationChannel::factory()->for($stranger)->create(['label' => 'Their Channel']);
    $theirs = alertColumnsWeatherRule($stranger, [$theirLocation], [$theirChannel]);
    $mine = alertColumnsWeatherRule($this->user, [Location::factory()->for($this->user)->create(['label' => 'Home'])], [], mode: 'location');

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Their Place')
        ->assertDontSee('Their Channel');
});

test('the number of table queries does not grow with the number of alerts', function () {
    // Counts only the table's own refresh: mounting the page also renders the header widgets, whose
    // queries legitimately depend on the data (Active now is per location); a parent refresh does not re-render them.
    $countQueries = function (int $alerts): int {
        AlertRule::query()->whereBelongsTo($this->user)->delete();

        foreach (range(1, $alerts) as $i) {
            $locations = Location::factory()->for($this->user)->count(2)->create()->all();
            $channels = NotificationChannel::factory()->for($this->user)->count(2)->create()->all();
            alertColumnsWeatherRule($this->user, $locations, $channels);
            AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create();
        }

        $component = livewire(ListAlertRules::class)->assertSuccessful();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh')->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $one = $countQueries(1);
    $six = $countQueries(6);

    expect($six)->toBe($one);
});

test('a list longer than three is rendered expandable', function () {
    $locations = collect(['One', 'Two', 'Three', 'Four'])
        ->map(fn (string $label) => Location::factory()->for($this->user)->create(['label' => $label]))
        ->all();
    alertColumnsWeatherRule($this->user, $locations);

    livewire(ListAlertRules::class)
        ->assertSeeHtmlInOrder(['isLimited: true', 'One', 'Two', 'Three', 'x-show="! isLimited"', 'Four'])
        ->assertSeeHtml('x-on:click.prevent.stop="isLimited = false"');
});

test('mode labels and None render as gray badges while location names are colored', function () {
    alertColumnsWeatherRule($this->user, mode: 'nationwide');
    alertColumnsWeatherRule($this->user, [Location::factory()->for($this->user)->create(['label' => 'Cabin'])]);
    alertColumnsWeatherRule($this->user, []);

    $html = livewire(ListAlertRules::class)->html();

    // Filament's gray badge carries no fi-color-* class; any other color does (fi-color-primary, ...).
    $badgeClasses = function (string $text) use ($html): string {
        preg_match('/<span class="([^"]*\bfi-badge\b[^"]*)"[^>]*>\s*'.preg_quote($text, '/').'\s*</s', $html, $matches);

        return $matches[1] ?? 'BADGE NOT FOUND';
    };

    expect($badgeClasses('Nationwide'))->not->toContain('fi-color-')->not->toBe('BADGE NOT FOUND')
        ->and($badgeClasses('None'))->not->toContain('fi-color-')->not->toBe('BADGE NOT FOUND')
        ->and($badgeClasses('Cabin'))->toContain('fi-color-primary');
});

test('a rule whose products all share a mode shows that mode', function () {
    $rule = alertColumnsPerProductRule($this->user, ['Tornado Warning' => ['nationwide', []], 'Severe Thunderstorm Warning' => ['nationwide', []]]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Nationwide'], $rule);
});

test('products sharing a location mode list the union of their locations', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $cabin = Location::factory()->for($this->user)->create(['label' => 'Cabin']);
    $rule = alertColumnsPerProductRule($this->user, ['Tornado Warning' => ['polygon', [$cabin]], 'Flood Warning' => ['polygon', [$home, $cabin]]]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home', 'Cabin'], $rule);
});

test('products with different modes show Mixed and the union of their locations', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $cabin = Location::factory()->for($this->user)->create(['label' => 'Cabin']);
    $rule = alertColumnsPerProductRule($this->user, [
        'Tornado Warning' => ['polygon', [$cabin, $home]],
        'Flood Warning' => ['location', [$home]],
        'Special Marine Warning' => ['nationwide', []],
    ]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Mixed', 'Home', 'Cabin'], $rule);
});

test('mixed modes with no locations show only Mixed, as a gray badge', function () {
    alertColumnsPerProductRule($this->user, ['Tornado Warning' => ['nationwide', []], 'Flood Warning' => ['wfo', []]]);

    $html = livewire(ListAlertRules::class)->html();
    preg_match('/<span class="([^"]*\bfi-badge\b[^"]*)"[^>]*>\s*Mixed\s*</s', $html, $matches);

    expect($matches[1] ?? 'BADGE NOT FOUND')->not->toBe('BADGE NOT FOUND')->not->toContain('fi-color-');
});

test('an old-style rule with products still reads from the rule itself', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $old = alertColumnsWeatherRule($this->user, [$home]);
    $old->products()->attach(Product::factory()->create()->id);
    $oldNationwide = alertColumnsWeatherRule($this->user, mode: 'nationwide');
    $oldNationwide->products()->attach(Product::factory()->count(2)->create()->pluck('id'));

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home'], $old)
        ->assertTableColumnStateSet('location_labels', ['Nationwide'], $oldNationwide);
});

test('the number of table queries does not grow with the number of per-product alerts', function () {
    $countQueries = function (int $alerts): int {
        AlertRule::query()->whereBelongsTo($this->user)->delete();

        foreach (range(1, $alerts) as $i) {
            $locations = Location::factory()->for($this->user)->count(2)->create()->all();
            alertColumnsPerProductRule($this->user, ["Tornado {$i}" => ['polygon', $locations], "Flood {$i}" => ['location', $locations], "Wind {$i}" => ['nationwide', []]]);
        }

        $component = livewire(ListAlertRules::class)->assertSuccessful();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh')->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $one = $countQueries(1);
    $six = $countQueries(6);

    expect($six)->toBe($one);
});

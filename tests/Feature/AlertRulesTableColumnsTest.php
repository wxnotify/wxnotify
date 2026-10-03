<?php

use App\Enums\MatchingMode;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
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
function weatherRule(User $user, array $locations = [], array $channels = [], string $mode = 'location'): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null,
    ]);
    $rule->locations()->attach(collect($locations)->pluck('id'));
    $rule->notificationChannels()->attach(collect($channels)->pluck('id'));

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
    $rule = weatherRule($this->user, [$home, $cabin]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home', 'Cabin'], $rule);
});

test('polygon and risk-area alerts list their saved locations too', function (string $mode) {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $rule = weatherRule($this->user, [$home], mode: $mode);

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
    $rule = weatherRule($this->user, mode: $mode);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', [$label], $rule);
})->with([
    'nationwide' => ['nationwide', 'Nationwide'],
    'wfo' => ['wfo', 'Issuing office'],
    'county/zone' => ['county_zone', 'County/zone'],
]);

test('an alert that should have locations but has none shows None', function () {
    $listless = weatherRule($this->user, []);
    $orphaned = AlertRule::factory()->dailySummary()->for($this->user)->create(['location_id' => null]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['None'], $listless)
        ->assertTableColumnStateSet('location_labels', ['None'], $orphaned);
});

test('the channels column lists each attached channel, and shows None when there are none', function () {
    $discord = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    $email = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email']);
    $with = weatherRule($this->user, [], [$discord, $email], mode: 'nationwide');
    $without = weatherRule($this->user, [], [], mode: 'nationwide');

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
    $theirs = weatherRule($stranger, [$theirLocation], [$theirChannel]);
    $mine = weatherRule($this->user, [Location::factory()->for($this->user)->create(['label' => 'Home'])], [], mode: 'location');

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Their Place')
        ->assertDontSee('Their Channel');
});

test('the number of queries does not grow with the number of alerts', function () {
    $countQueries = function (int $alerts): int {
        AlertRule::query()->whereBelongsTo($this->user)->delete();

        foreach (range(1, $alerts) as $i) {
            $locations = Location::factory()->for($this->user)->count(2)->create()->all();
            $channels = NotificationChannel::factory()->for($this->user)->count(2)->create()->all();
            weatherRule($this->user, $locations, $channels);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(ListAlertRules::class)->assertSuccessful();
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
    weatherRule($this->user, $locations);

    livewire(ListAlertRules::class)
        ->assertSeeHtmlInOrder(['isLimited: true', 'One', 'Two', 'Three', 'x-show="! isLimited"', 'Four'])
        ->assertSeeHtml('x-on:click.prevent.stop="isLimited = false"');
});

<?php

use App\Actions\Pipeline\ReplayIngestedProduct;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function replayForecastDiscussion(array $overrides = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'ktwc', 'ttaaii' => 'fxus65', 'awipsid' => 'afdtwc', 'wmo_ddhhmm' => '221432',
        'text' => "FXUS65 KTWC 221432\nAFDTWC\n\nAREA FORECAST DISCUSSION\nNational Weather Service Tucson AZ",
        'source' => 'nwws',
        ...$overrides,
    ]));
}

function replayTornadoWarning(array $overrides = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'awipsid' => 'torbou', 'wmo_ddhhmm' => '191622',
        'text' => "WFUS53 KBOU 191622\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN\nTornado Warning",
        'source' => 'emwin',
        'wmo_header_key' => 'wfus53 kbou 191622 torbou',
        ...$overrides,
    ]));
}

function replayRule(User $user, array $attributes): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create($attributes);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    return $rule;
}

test('a replay creates a test copy of the original and leaves the original untouched', function () {
    $original = replayForecastDiscussion();

    $copy = app(ReplayIngestedProduct::class)($original);

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->is_test)->toBeTrue()
        ->and($copy->replay_of_id)->toBe($original->id)
        ->and($copy->text)->toBe($original->text)
        ->and($copy->cccc)->toBe('ktwc')
        ->and($copy->ttaaii)->toBe('fxus65')
        ->and($copy->awipsid)->toBe('afdtwc')
        ->and($copy->wmo_ddhhmm)->toBe('221432')
        ->and($copy->source)->toBe('nwws')
        ->and($copy->test_product_scenario_id)->toBeNull()
        ->and($copy->file_path)->toStartWith("replay/{$original->id}-")
        ->and($copy->view_slug)->not->toBeNull();

    $fresh = $original->fresh();
    expect($fresh->is_test)->toBeFalse()
        ->and($fresh->replay_of_id)->toBeNull()
        ->and(IngestedProduct::count())->toBe(2);
});

test('the copy never carries the original wmo header key so intake dedup only sees the original', function () {
    $original = replayTornadoWarning();

    $copy = app(ReplayIngestedProduct::class)($original);

    expect($copy->wmo_header_key)->toBeNull()
        ->and(IngestedProduct::where('wmo_header_key', 'wfus53 kbou 191622 torbou')->pluck('id')->all())->toBe([$original->id]);
});

test('a replayed non-vtec product is delivered only to test account rules', function () {
    Queue::fake();
    $realRule = replayRule(User::factory()->create(['is_test_account' => false]), ['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $testRule = replayRule(User::factory()->create(['is_test_account' => true]), ['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);

    $copy = app(ReplayIngestedProduct::class)(replayForecastDiscussion());

    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id && $job->ingestedProductId === $copy->id);
});

test('a replayed vtec product is delivered only to test account rules and never writes active_wwas', function () {
    Queue::fake();
    $tornado = Product::where('code', 'TO.W')->sole();
    $realRule = replayRule(User::factory()->create(['is_test_account' => false]), ['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $realRule->products()->attach($tornado);
    $testRule = replayRule(User::factory()->create(['is_test_account' => true]), ['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $testRule->products()->attach($tornado);

    $copy = app(ReplayIngestedProduct::class)(replayTornadoWarning());

    expect($copy->vtec_office)->not->toBeNull()
        ->and(ActiveWwa::count())->toBe(0);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id && $job->ingestedProductId === $copy->id);
});

test('a replay never touches an existing real active_wwas row', function () {
    $original = replayTornadoWarning();
    $real = ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'is_test' => false,
        'vtec_office' => 'KBOU', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0009', 'vtec_year' => 2026,
    ]);
    $before = $real->fresh()->only(['status', 'latest_ingested_product_id', 'updated_at']);

    app(ReplayIngestedProduct::class)($original);

    expect(ActiveWwa::count())->toBe(1)
        ->and($real->fresh()->only(['status', 'latest_ingested_product_id', 'updated_at']))->toEqual($before);
});

test('a replay refuses to run in production', function () {
    $original = replayForecastDiscussion();
    app()->instance('env', 'production');

    expect(ReplayIngestedProduct::allowed())->toBeFalse()
        ->and(fn () => app(ReplayIngestedProduct::class)($original))->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1);
});

test('replaying is allowed outside production', function () {
    expect(ReplayIngestedProduct::allowed())->toBeTrue();
});

test('a product whose text cannot be re-ingested throws instead of returning nothing', function () {
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['format' => 'cap', 'text' => 'this is not a cap document']));

    expect(fn () => app(ReplayIngestedProduct::class)($original))->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1);
});

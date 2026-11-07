<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Events\ProductIngested;
use App\Jobs\ProcessIngestedProduct;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The suite runs with the null broadcaster, which skips the announcement entirely.
    config(['broadcasting.default' => 'reverb']);
});

test('a processed product is announced once with a payload that carries no text', function () {
    Event::fake([ProductIngested::class]);
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'ktbw', 'awipsid' => 'afdtbw', 'text' => 'SECRET BODY TEXT', 'received_at' => '2026-11-05 12:30:00',
    ]));

    (new ProcessIngestedProduct($product->id))->handle();

    Event::assertDispatchedTimes(ProductIngested::class, 1);
    Event::assertDispatched(ProductIngested::class, function (ProductIngested $event) use ($product): bool {
        $payload = $event->broadcastWith();

        return $payload['id'] === $product->id
            && $payload['office'] === 'TBW'
            && $payload['issued_at'] === $product->received_at->toIso8601String()
            && $payload['headline'] === null
            && $payload['name'] === $product->displayTitle()
            && ! str_contains(json_encode($payload), 'SECRET')
            && ! array_key_exists('view_slug', $payload);
    });
});

test('the headline joins the event names of the product segments and the name falls back to a generic label', function () {
    Event::fake([ProductIngested::class]);
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'nonsense', 'awipsid' => null, 'ttaaii' => null, 'vtec_office' => null,
    ]));
    foreach (['Tornado Warning', 'Flash Flood Warning'] as $position => $name) {
        IngestedProductSegment::factory()->for($product)->create([
            'position' => $position, 'product_id' => Product::factory()->create(['name' => $name])->id,
        ]);
    }

    (new ProcessIngestedProduct($product->id))->handle();

    Event::assertDispatched(ProductIngested::class, function (ProductIngested $event): bool {
        $payload = $event->broadcastWith();

        return $payload['headline'] === 'Tornado Warning + Flash Flood Warning'
            && $payload['office'] === null
            && $payload['name'] === 'Weather product';
    });
});

test('the event is queued on the private live-products channel', function () {
    $event = new ProductIngested(IngestedProduct::factory()->create());

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('ProductIngested')
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-live-products');
});

test('test products and replays are never announced', function () {
    Event::fake([ProductIngested::class]);
    [$test, $replay] = IngestedProduct::withoutEvents(function () {
        $original = IngestedProduct::factory()->create();

        return [
            IngestedProduct::factory()->create(['is_test' => true]),
            IngestedProduct::factory()->create(['replay_of_id' => $original->id, 'is_test' => true]),
        ];
    });

    (new ProcessIngestedProduct($test->id))->handle();
    (new ProcessIngestedProduct($replay->id))->handle();

    Event::assertNotDispatched(ProductIngested::class);
});

test('a failing broadcaster is logged by class only and never stops the processing', function () {
    Log::spy();
    $match = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $match);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('secret detail'));
    $dispatcher->shouldIgnoreMissing();
    app()->instance('events', $dispatcher);
    Facade::clearResolvedInstance('events');
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    (new ProcessIngestedProduct($product->id))->handle();

    $match->shouldHaveReceived('__invoke')->once();
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context === [
        'ingested_product_id' => $product->id,
        'exception' => RuntimeException::class,
    ]);
});

test('the broadcast rides its own queue with short, bounded limits', function () {
    $event = new ProductIngested(IngestedProduct::factory()->create());

    expect($event->broadcastQueue())->toBe('broadcasts')
        ->and(config('broadcasting.connections.reverb.client_options.connect_timeout'))->toBe(2)
        ->and(config('broadcasting.connections.reverb.client_options.timeout'))->toBe(3);

    config(['broadcasting.default' => 'reverb']);
    Queue::fake();
    event($event);

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->tries === 1 && $job->timeout === 15);
});

test('with the null broadcaster nothing is built, queued or queried for the headline', function () {
    config(['broadcasting.default' => 'null']);
    Queue::fake();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    DB::enableQueryLog();
    (new ProcessIngestedProduct($product->id))->handle();
    $segmentQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'ingested_product_segments') && str_contains($q['query'], 'select'));

    Queue::assertNotPushed(BroadcastEvent::class);
    // buildSegments reads the segments once; the announcement must add none of its own.
    expect($segmentQueries->count())->toBeLessThanOrEqual(1)
        ->and((new ProductIngested($product))->broadcastWhen())->toBeFalse();

    config(['broadcasting.default' => null]);
    expect((new ProductIngested($product))->broadcastWhen())->toBeFalse();

    config(['broadcasting.default' => 'reverb']);
    expect((new ProductIngested($product))->broadcastWhen())->toBeTrue();
});

test('the event constructor reads the segments once', function () {
    $product = IngestedProduct::factory()->create();
    IngestedProductSegment::factory()->for($product)->create(['position' => 0, 'product_id' => Product::factory()->create(['name' => 'Tornado Warning'])->id]);

    DB::enableQueryLog();
    $event = new ProductIngested($product);
    $reads = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'ingested_product_segments'));

    expect($reads)->toHaveCount(1)
        ->and($event->broadcastWith()['headline'])->toBe('Tornado Warning');
});

test('a verified user may listen on the channel but an unverified one may not', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app('Illuminate\Contracts\Broadcasting\Factory')->purge('reverb');
    require base_path('routes/channels.php');
    $body = ['socket_id' => '1234.5678', 'channel_name' => 'private-live-products'];

    $this->actingAs(User::factory()->unverified()->create())->postJson('/broadcasting/auth', $body)->assertForbidden();
    $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $body)->assertSuccessful();
});

test('any signed-in user may listen on the channel and a guest is refused', function () {
    // The null driver authorizes everyone, so use a real driver (no server is contacted by auth).
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app('Illuminate\Contracts\Broadcasting\Factory')->purge('reverb');
    require base_path('routes/channels.php');
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-live-products'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-live-products'])
        ->assertSuccessful();
});

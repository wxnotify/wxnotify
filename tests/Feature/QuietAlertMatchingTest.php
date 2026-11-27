<?php

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\ListAppAlerts;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Enums\Tier;
use App\Jobs\DeliverWeatherAlert;
use App\Jobs\SendQuietAlertBadge;
use App\Models\AlertRule;
use App\Models\ChannelType;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->for($this->user)->create(['timezone' => 'America/New_York', 'forecast_zone_ugc' => 'NYZ072']);
    $this->rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $this->rule->locations()->attach($location);
    $this->rule->products()->attach($this->product);

    $this->ingested = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'product_id' => $this->product->id, 'ugc_codes' => ['NYZ072'],
    ]));

    $this->attach = function (NotificationChannel ...$channels): void {
        $this->rule->notificationChannels()->attach(collect($channels)->pluck('id')->all());
    };
    $this->phone = fn (string $platform = 'ios', string $token = 'secret-device-token'): NotificationChannel => NotificationChannel::factory()
        ->for($this->user)->mobilePush($token, $platform)->create();
    $this->match = fn () => (new MatchWeatherAlertRules)($this->ingested->fresh());
    $this->rowFor = fn (NotificationChannel $channel): DeliveryLog => DeliveryLog::where('notification_channel_id', $channel->id)->sole();

    $this->logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged->push($event->message.' '.json_encode($event->context)));
});

test('a quiet match puts the held phone alert in the app as unread', function () {
    Queue::fake();
    $phone = ($this->phone)();
    ($this->attach)($phone);

    ($this->match)();

    $row = ($this->rowFor)($phone);
    expect($row->status)->toBe('suppressed')
        ->and($row->in_app)->toBeTrue()
        ->and($row->read_at)->toBeNull();
});

test('a held iPhone alert queues one badge job for its channel', function () {
    Queue::fake();
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);

    ($this->match)();

    Queue::assertPushed(SendQuietAlertBadge::class, 1);
    Queue::assertPushed(SendQuietAlertBadge::class, fn (SendQuietAlertBadge $job) => $job->channelId === $phone->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a held Android alert reaches the app and queues nothing', function () {
    Queue::fake();
    $phone = ($this->phone)('android');
    ($this->attach)($phone);

    ($this->match)();

    expect(($this->rowFor)($phone)->in_app)->toBeTrue();
    Queue::assertNothingPushed();
});

test('a held alert on a channel that is not a phone stays unmarked', function () {
    Queue::fake();
    // Phone-shaped config must not make another channel type eligible.
    $email = NotificationChannel::factory()->for($this->user)->email()->create(['config' => ['to' => 'a@example.com', 'platform' => 'ios', 'fcm_token' => 'x']]);
    ($this->attach)($email);

    ($this->match)();

    $row = ($this->rowFor)($email);
    expect($row->status)->toBe('suppressed')->and($row->in_app)->toBeFalse();
    Queue::assertNothingPushed();
});

test('an iPhone and an Android phone are both marked, with one badge job and one unread alert', function () {
    Queue::fake();
    $iphone = ($this->phone)('ios', 'ios-token');
    $android = ($this->phone)('android', 'android-token');
    ($this->attach)($iphone, $android);

    ($this->match)();

    expect(($this->rowFor)($iphone)->in_app)->toBeTrue()
        ->and(($this->rowFor)($android)->in_app)->toBeTrue()
        ->and(app(CountUnreadAppAlerts::class)($this->user))->toBe(1);
    Queue::assertPushed(SendQuietAlertBadge::class, 1);
    Queue::assertPushed(SendQuietAlertBadge::class, fn (SendQuietAlertBadge $job) => $job->channelId === $iphone->id);
});

test('a phone that could not be pushed to is held as before, with no badge job', function (Closure $break) {
    Queue::fake();
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);
    $break->call($this, $phone);

    ($this->match)();

    $row = ($this->rowFor)($phone);
    expect($row->status)->toBe('suppressed')->and($row->in_app)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'unverified' => [function (NotificationChannel $phone) {
        $phone->forceFill(['verified_at' => null])->save();
    }],
    'an owner below the channel type tier' => [function () {
        expect(ChannelType::query()->where('slug', 'mobile_push')->update(['min_tier' => Tier::Pro]))->toBe(1);
    }],
    'an account pending deletion' => [function () {
        $this->user->forceFill(['deletion_requested_at' => now()])->save();
    }],
    'a missing token' => [function (NotificationChannel $phone) {
        $phone->update(['config' => ['platform' => 'ios']]);
    }],
    'a blank token' => [function (NotificationChannel $phone) {
        $phone->update(['config' => ['platform' => 'ios', 'fcm_token' => '']]);
    }],
]);

test('an eligibility check that throws still writes the held row, and logs only the class', function () {
    Queue::fake();
    $this->mock(ChannelDispatcher::class)->shouldReceive('refusalFor')->andThrow(new RuntimeException('secret-device-token'));
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);

    ($this->match)();

    $row = ($this->rowFor)($phone);
    expect($row->status)->toBe('suppressed')
        ->and($row->in_app)->toBeFalse()
        ->and($this->logged->all())->toBe(['Held alert app check failed: RuntimeException []']);
    Queue::assertNothingPushed();
});

test('a database error in the eligibility check does not abort the transaction or lose the held row', function () {
    Queue::fake();
    // A real PostgreSQL error inside the test's open transaction: without a savepoint every later statement fails.
    $this->mock(ChannelDispatcher::class)->shouldReceive('refusalFor')->andReturnUsing(function (): ?string {
        DB::select('select * from table_that_does_not_exist');

        return null;
    });
    $first = ($this->phone)('ios', 'first-token');
    $second = ($this->phone)('ios', 'second-token');
    ($this->attach)($first, $second);

    ($this->match)();

    expect(($this->rowFor)($first)->status)->toBe('suppressed')
        ->and(($this->rowFor)($first)->in_app)->toBeFalse()
        ->and(($this->rowFor)($second)->status)->toBe('suppressed')
        ->and($this->logged->all())->toBe(array_fill(0, 2, 'Held alert app check failed: '.QueryException::class.' []'));
});

test('only a marked held row names in_app in its insert, so the others survive a database not yet migrated', function () {
    Queue::fake();
    $email = NotificationChannel::factory()->for($this->user)->email()->create();
    $phone = ($this->phone)('ios');
    ($this->attach)($email, $phone);
    // Each insert into delivery_logs, keyed by the channel type it wrote.
    $inserts = [];
    DB::listen(function (QueryExecuted $query) use (&$inserts): void {
        if (str_starts_with($query->sql, 'insert into "delivery_logs"')) {
            $channelType = collect($query->bindings)->first(fn (mixed $binding): bool => in_array($binding, ['email', 'mobile_push'], true));
            $inserts[$channelType] = $query->sql;
        }
    });

    ($this->match)();

    expect($inserts)->toHaveKeys(['email', 'mobile_push'])
        ->and($inserts['email'])->not->toContain('in_app')
        ->and($inserts['mobile_push'])->toContain('"in_app"')
        ->and(($this->rowFor)($email)->in_app)->toBeFalse()
        ->and(($this->rowFor)($phone)->in_app)->toBeTrue();
});

test('a channel whose config cannot be decrypted is still held, and the other channels are unaffected', function () {
    Queue::fake();
    $broken = ($this->phone)('ios', 'broken-token');
    $healthy = ($this->phone)('ios', 'healthy-token');
    ($this->attach)($broken, $healthy);
    DB::table('notification_channels')->where('id', $broken->id)->update(['config' => 'not-encrypted']);

    ($this->match)();

    expect(($this->rowFor)($broken)->status)->toBe('suppressed')
        ->and(($this->rowFor)($broken)->in_app)->toBeFalse()
        ->and(($this->rowFor)($healthy)->in_app)->toBeTrue();
    Queue::assertPushed(SendQuietAlertBadge::class, 1);
    Queue::assertPushed(SendQuietAlertBadge::class, fn (SendQuietAlertBadge $job) => $job->channelId === $healthy->id);
});

test('a badge job that cannot be queued keeps the held row and its segments, and the next channel is still held', function () {
    $segment = IngestedProductSegment::factory()->create([
        'ingested_product_id' => $this->ingested->id, 'product_id' => $this->product->id, 'ugc_codes' => ['NYZ072'],
    ]);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'));
    $iphone = ($this->phone)('ios', 'ios-token');
    $android = ($this->phone)('android', 'android-token');
    ($this->attach)($iphone, $android);

    ($this->match)();

    expect(($this->rowFor)($iphone)->in_app)->toBeTrue()
        ->and(($this->rowFor)($iphone)->segments->modelKeys())->toBe([$segment->id])
        ->and(($this->rowFor)($android)->in_app)->toBeTrue()
        ->and($this->logged->all())->toBe(['Failed to queue the quiet alert badge: RuntimeException '.json_encode(['channel_id' => $iphone->id])]);
});

test('matching the same product again adds no row and no badge job', function () {
    Queue::fake();
    ($this->attach)(($this->phone)('ios'));

    ($this->match)();
    ($this->match)();

    expect(DeliveryLog::count())->toBe(1);
    Queue::assertPushed(SendQuietAlertBadge::class, 1);
});

test('two held alerts for one iPhone leave one badge job waiting, and both reach the app', function () {
    config(['queue.default' => 'database']);
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);
    $later = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'product_id' => $this->product->id, 'ugc_codes' => ['NYZ072'],
    ]));

    ($this->match)();
    (new MatchWeatherAlertRules)($later);

    expect(DB::table('jobs')->pluck('queue')->all())->toBe(['broadcasts'])
        ->and(DeliveryLog::where('in_app', true)->count())->toBe(2);
});

test('a badge lock that cannot be taken keeps the held row, and the next channel is still held', function () {
    $this->mock(Cache::class)->shouldReceive('lock')->andThrow(new RuntimeException('cache is down'));
    $iphone = ($this->phone)('ios', 'ios-token');
    $android = ($this->phone)('android', 'android-token');
    ($this->attach)($iphone, $android);

    ($this->match)();

    expect(($this->rowFor)($iphone)->in_app)->toBeTrue()
        ->and(($this->rowFor)($android)->in_app)->toBeTrue()
        ->and($this->logged->all())->toBe(['Failed to queue the quiet alert badge: RuntimeException '.json_encode(['channel_id' => $iphone->id])]);
});

test('matching a quiet alert makes no network request', function () {
    Queue::fake();
    // With working credentials, so a push sent from matching itself would reach the fake.
    $credentials = fcmUseCredentials();
    Http::preventStrayRequests();
    Http::fake();
    ($this->attach)(($this->phone)('ios'));

    ($this->match)();

    Http::assertNothingSent();
    Queue::assertPushed(SendQuietAlertBadge::class, 1);
    @unlink($credentials['path']);
});

test('outside quiet time the alert is delivered as before, with no held row or badge job', function () {
    Queue::fake();
    $this->rule->update(['quiet_hours_start' => null, 'quiet_hours_end' => null]);
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);

    ($this->match)();

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $this->rule->id && $job->channelId === $phone->id);
    Queue::assertNotPushed(SendQuietAlertBadge::class);
    expect(DeliveryLog::count())->toBe(0);
});

test('a delivery outside quiet time writes an unmarked row', function () {
    $credentials = fcmUseCredentials();
    fcmFakeGoogle();
    $this->rule->update(['quiet_hours_start' => null, 'quiet_hours_end' => null]);
    $phone = ($this->phone)('ios');
    ($this->attach)($phone);

    ($this->match)();

    $row = ($this->rowFor)($phone);
    expect($row->status)->toBe('success')->and($row->in_app)->toBeFalse();
    @unlink($credentials['path']);
});

test('end to end, a quiet match sends one badge-only push and the alert is in the app feed', function () {
    $credentials = fcmUseCredentials();
    fcmFakeGoogle();
    ($this->attach)(($this->phone)('ios'));

    ($this->match)();

    $pushes = Http::recorded(fn (Request $request) => str_contains($request->url(), 'messages:send'));
    expect($pushes)->toHaveCount(1);

    $message = $pushes->first()[0]['message'];
    expect($message)->not->toHaveKeys(['notification', 'data'])
        ->and($message['apns']['payload']['aps'])->toBe(['badge' => 1])
        ->and(app(ListAppAlerts::class)($this->user)['alerts'])->toHaveCount(1)
        ->and(DeliveryLog::sole()->status)->toBe('suppressed');
    @unlink($credentials['path']);
});

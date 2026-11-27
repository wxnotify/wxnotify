<?php

use App\Enums\Tier;
use App\Jobs\PruneMobilePushChannel;
use App\Jobs\SendQuietAlertBadge;
use App\Models\ChannelType;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    $this->user = User::factory()->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->mobilePush('secret-device-token', 'ios')->create();

    $this->logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged->push($event->message.' '.json_encode($event->context)));

    // A held phone alert shown in the app: one unread alert.
    $this->heldRow = fn () => DeliveryLog::factory()->for($this->user)->create([
        'channel_type' => 'mobile_push',
        'status' => 'suppressed',
        'in_app' => true,
    ]);

    $this->run = fn (?int $channelId = null) => app()->call([new SendQuietAlertBadge($channelId ?? $this->channel->id), 'handle']);

    $this->sentBadges = fn (): array => Http::recorded(fn (Request $request) => str_contains($request->url(), 'messages:send'))
        ->map(fn (array $pair) => $pair[0]['message']['apns']['payload']['aps']['badge'])
        ->values()->all();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('an iPhone gets the unread count as it is when the job runs', function () {
    fcmFakeGoogle();
    $job = new SendQuietAlertBadge($this->channel->id);
    ($this->heldRow)();
    ($this->heldRow)();

    app()->call([$job, 'handle']);

    expect(($this->sentBadges)())->toBe([2])
        ->and($this->logged)->toBeEmpty();
});

test('an iPhone with nothing unread gets a badge of zero', function () {
    fcmFakeGoogle();

    ($this->run)();

    expect(($this->sentBadges)())->toBe([0]);
});

test('a channel that may not be sent to gets no request', function (Closure $arrange) {
    Http::fake();
    ($this->heldRow)();
    $channelId = $arrange->call($this) ?? $this->channel->id;

    ($this->run)($channelId);

    Http::assertNothingSent();
})->with([
    'a deleted channel' => [function () {
        $this->channel->delete();
    }],
    'an Android phone' => [fn () => NotificationChannel::factory()->for($this->user)->mobilePush('android-token', 'android')->create()->id],
    'a channel that is not a phone' => [fn () => NotificationChannel::factory()->for($this->user)->email()->create(['config' => ['to' => 'a@example.com', 'platform' => 'ios', 'fcm_token' => 'x']])->id],
    'an unverified phone' => [function () {
        $this->channel->forceFill(['verified_at' => null])->save();
    }],
    'an owner below the channel type tier' => [function () {
        expect(ChannelType::query()->where('slug', 'mobile_push')->update(['min_tier' => Tier::Pro]))->toBe(1);
    }],
    'an account pending deletion' => [function () {
        $this->user->forceFill(['deletion_requested_at' => now()])->save();
    }],
    'a missing token' => [function () {
        $this->channel->update(['config' => ['platform' => 'ios']]);
    }],
]);

test('the dispatcher refuses a badge for the same reasons as an alert, without a request', function () {
    Http::fake();
    $dispatcher = app(ChannelDispatcher::class);

    expect($dispatcher->refusalFor($this->channel))->toBeNull();

    $this->channel->forceFill(['verified_at' => null])->save();

    expect($dispatcher->refusalFor($this->channel))->toBe(ChannelDispatcher::UNCONFIRMED_REFUSAL)
        ->and($dispatcher->sendAppBadge($this->channel, 1)->error)->toBe(ChannelDispatcher::UNCONFIRMED_REFUSAL)
        ->and($dispatcher->send($this->channel, 'body')->error)->toBe(ChannelDispatcher::UNCONFIRMED_REFUSAL);
    Http::assertNothingSent();
});

test('the dispatcher sends no badge to an Android phone or a channel that is not a phone', function (string $kind) {
    Http::fake();
    $channel = $kind === 'android'
        ? NotificationChannel::factory()->for($this->user)->mobilePush('android-token', 'android')->create()
        : NotificationChannel::factory()->for($this->user)->email()->create();

    expect(app(ChannelDispatcher::class)->sendAppBadge($channel, 1)->success)->toBeFalse();
    Http::assertNothingSent();
})->with(['android', 'email']);

test('a failed badge push records nothing and logs no token', function (Closure $response) {
    fcmFakeGoogle($response());
    $row = ($this->heldRow)();
    $before = $row->fresh()->getAttributes();

    ($this->run)();

    expect(DeliveryLog::count())->toBe(1)
        ->and($row->fresh()->getAttributes())->toBe($before)
        ->and($this->logged)->toHaveCount(1)
        ->and($this->logged->first())->toStartWith('Quiet alert badge push failed: ')
        ->not->toContain('secret-device-token')
        ->not->toContain('secret-response-detail');
})->with([
    'Firebase refuses it' => [fn () => Http::response(['error' => ['message' => 'secret-response-detail secret-device-token']], 500)],
    'Firebase cannot be reached' => [fn () => fn () => throw new ConnectionException('secret-response-detail secret-device-token')],
    'something throws' => [fn () => fn () => throw new RuntimeException('secret-response-detail secret-device-token')],
]);

test('a thrown exception is logged by its class only', function () {
    fcmFakeGoogle(fn () => throw new RuntimeException('secret-device-token'));

    ($this->run)();

    expect($this->logged->all())->toBe(['Quiet alert badge push failed: RuntimeException []']);
});

test('a dead token queues the same delayed prune as an alert push', function () {
    Queue::fake();
    fcmFakeGoogle(Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404));

    ($this->run)();

    Queue::assertPushed(PruneMobilePushChannel::class, fn (PruneMobilePushChannel $job) => $job->channelId === $this->channel->id
        && $job->tokenHash === sha1('secret-device-token')
        && $job->delay === 60);
    expect(DeliveryLog::count())->toBe(0);
});

test('the badge job goes on the broadcasts queue, behind product processing, and gets one attempt', function () {
    config(['queue.default' => 'database']);

    SendQuietAlertBadge::dispatch($this->channel->id);

    expect(DB::table('jobs')->sole()->queue)->toBe('broadcasts')
        ->and((new SendQuietAlertBadge($this->channel->id))->tries)->toBe(1);
});

test('a second badge for a phone is dropped while one is still waiting', function () {
    config(['queue.default' => 'database']);

    SendQuietAlertBadge::dispatch($this->channel->id);
    SendQuietAlertBadge::dispatch($this->channel->id);

    expect(DB::table('jobs')->count())->toBe(1);
});

test('each phone has its own waiting badge', function () {
    config(['queue.default' => 'database']);
    $other = NotificationChannel::factory()->for($this->user)->mobilePush('other-token', 'ios')->create();

    SendQuietAlertBadge::dispatch($this->channel->id);
    SendQuietAlertBadge::dispatch($other->id);

    expect(DB::table('jobs')->count())->toBe(2);
});

test('an alert held while the badge job is running queues another, which sends the newer count', function () {
    config(['queue.default' => 'database']);
    // The first push is in flight, its count already read, when the second alert is held.
    $held = false;
    fcmFakeGoogle(function () use (&$held) {
        if (! $held) {
            $held = true;
            ($this->heldRow)();
            SendQuietAlertBadge::dispatch($this->channel->id);
        }

        return Http::response(['name' => 'projects/p/messages/1']);
    });
    ($this->heldRow)();
    SendQuietAlertBadge::dispatch($this->channel->id);

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'broadcasts', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    expect(($this->sentBadges)())->toBe([1, 2])
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a badge left waiting for ten minutes no longer blocks a new one', function () {
    config(['queue.default' => 'database']);
    $this->freezeTime();
    SendQuietAlertBadge::dispatch($this->channel->id);

    $this->travel(599)->seconds();
    SendQuietAlertBadge::dispatch($this->channel->id);
    expect(DB::table('jobs')->count())->toBe(1);

    $this->travel(2)->seconds();
    SendQuietAlertBadge::dispatch($this->channel->id);
    expect(DB::table('jobs')->count())->toBe(2);
});

test('the badge lock is keyed by the channel id alone', function () {
    expect(UniqueLock::getKey(new SendQuietAlertBadge(42)))->toBe('laravel_unique_job:'.SendQuietAlertBadge::class.':42');
});

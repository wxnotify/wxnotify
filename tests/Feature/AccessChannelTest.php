<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\Location;
use App\Models\User;
use App\Support\AccessChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    Route::middleware('web')->get('/_test/channel-web', fn (Request $request) => (string) AccessChannel::for($request));
    Route::middleware('auth:sanctum')->get('/_test/channel-api', fn (Request $request) => (string) AccessChannel::for($request));
});

test('a browser request is web, signed in or not', function () {
    $this->get('/_test/channel-web')->assertContent('web');

    $this->actingAs(User::factory()->create())->get('/_test/channel-web')->assertContent('web');
});

test('a request carrying the app\'s token is the mobile app', function () {
    $token = User::factory()->create()->createToken('iPhone', ['app'])->plainTextToken;

    $this->withToken($token)->getJson('/_test/channel-api')->assertContent('mobile app');
});

test('a request with another kind of token says nothing', function () {
    $beacon = User::factory()->create()->createToken('beacon', ['location'])->plainTextToken;

    $this->withToken($beacon)->getJson('/_test/channel-api')->assertContent('');
});

test('no request at all (the console, the queue) says nothing', function () {
    expect(AccessChannel::for(Request::create('/')))->toBeNull();
});

test('the channel is the subscriber\'s own, never staff\'s or an impersonator\'s', function () {
    $subscriber = User::factory()->create();
    $staff = User::factory()->create(['is_staff' => true]);
    $request = Request::create('/_test/channel-web');
    $request->setRouteResolver(fn () => Route::getRoutes()->match(Request::create('/_test/channel-web')));
    $request->setLaravelSession(app('session.store'));

    expect(AccessChannel::forActivity($subscriber, $request))->toBe('web');

    $this->actingAs($subscriber);
    expect(AccessChannel::forActivity($subscriber, $request))->toBe('web');

    $this->actingAs($staff);
    expect(AccessChannel::forActivity($subscriber, $request))->toBeNull();

    $this->actingAs($subscriber);
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));
    expect(AccessChannel::forActivity($subscriber, $request))->toBeNull();
});

test('a sign-in through the browser alerts as via web', function () {
    Queue::fake();
    ActivityAlertRule::factory()->create(['scope' => 'all_subscribers', 'activity_types' => [ActivityAlertType::SignedIn->value], 'is_active' => true]);
    $user = User::factory()->create();
    Route::middleware('web')->get('/_test/sign-in', function () use ($user) {
        Auth::login($user);

        return 'ok';
    });

    $this->get('/_test/sign-in')->assertOk();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->userId === $user->id && $job->via === 'web');
});

test('a change made with the app\'s token alerts as via mobile app', function () {
    Queue::fake();
    ActivityAlertRule::factory()->create(['scope' => 'all_subscribers', 'activity_types' => [ActivityAlertType::LocationCreated->value], 'is_active' => true]);
    $user = User::factory()->create();
    $token = $user->createToken('iPhone', ['app'])->plainTextToken;
    Route::middleware('auth:sanctum')->post('/_test/make-location', function (Request $request) {
        Location::factory()->for($request->user())->create();

        return 'ok';
    });

    $this->withToken($token)->postJson('/_test/make-location')->assertOk();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->userId === $user->id && $job->via === 'mobile app');
});

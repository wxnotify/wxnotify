<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use STS\FilamentImpersonate\ImpersonateManager;

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    TrustProxies::flushState();
});

/**
 * A request as a proxy would forward it: from $peer, carrying $headers.
 *
 * @param  array<string, string>  $headers
 */
function proxiedRequest(string $peer, array $headers = []): Request
{
    $server = ['REMOTE_ADDR' => $peer];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/', 'GET', server: $server);
}

function trustProxies(?string $proxies, ?string $header = null): void
{
    config(['wxnotify.client_ip.trusted_proxies' => $proxies, 'wxnotify.client_ip.header' => $header]);
    ClientIp::configureProxies();
}

test('by default the connecting address is the client, and forwarding headers are ignored', function () {
    trustProxies(null);

    expect(ClientIp::for(proxiedRequest('198.51.100.7')))->toBe('198.51.100.7')
        ->and(ClientIp::for(proxiedRequest('198.51.100.7', ['X-Forwarded-For' => '203.0.113.9'])))->toBe('198.51.100.7')
        ->and(ClientIp::for(proxiedRequest('198.51.100.7', ['CF-Connecting-IP' => '203.0.113.9'])))->toBe('198.51.100.7');
});

test('behind a trusted load balancer the client is the address in X-Forwarded-For', function (string $trusted) {
    trustProxies($trusted);

    expect(ClientIp::for(proxiedRequest('10.0.0.5', ['X-Forwarded-For' => '203.0.113.9'])))->toBe('203.0.113.9');
})->with(['an exact address' => '10.0.0.5', 'a CIDR block' => '10.0.0.0/24', 'a list' => '192.0.2.1, 10.0.0.0/24', 'everything' => '*']);

test('through several trusted hops the client is the first address that is not a trusted proxy', function () {
    trustProxies('10.0.0.0/24');

    expect(ClientIp::for(proxiedRequest('10.0.0.5', ['X-Forwarded-For' => '203.0.113.9, 10.0.0.9'])))->toBe('203.0.113.9')
        ->and(ClientIp::for(proxiedRequest('10.0.0.5', ['X-Forwarded-For' => '198.51.100.1, 203.0.113.9, 10.0.0.9'])))->toBe('203.0.113.9');
});

test('X-Forwarded-For from a peer that is not a trusted proxy is ignored', function () {
    trustProxies('10.0.0.5');

    expect(ClientIp::for(proxiedRequest('198.51.100.7', ['X-Forwarded-For' => '203.0.113.9'])))->toBe('198.51.100.7');
});

test('a forwarded IPv6 client works too', function () {
    trustProxies('10.0.0.5');

    expect(ClientIp::for(proxiedRequest('10.0.0.5', ['X-Forwarded-For' => '2001:db8::1'])))->toBe('2001:db8::1');
});

test('behind Cloudflare the client is CF-Connecting-IP, when the connection comes from a trusted proxy', function () {
    trustProxies('173.245.48.0/20', 'CF-Connecting-IP');

    expect(ClientIp::for(proxiedRequest('173.245.48.9', ['CF-Connecting-IP' => '203.0.113.9', 'X-Forwarded-For' => '203.0.113.9'])))->toBe('203.0.113.9');
});

test('CF-Connecting-IP is ignored from an untrusted peer, so it cannot be forged', function () {
    trustProxies('173.245.48.0/20', 'CF-Connecting-IP');

    expect(ClientIp::for(proxiedRequest('198.51.100.7', ['CF-Connecting-IP' => '203.0.113.9'])))->toBe('198.51.100.7');
});

test('a missing or invalid CF-Connecting-IP falls back to the normal resolution', function (?string $value) {
    trustProxies('10.0.0.5', 'CF-Connecting-IP');

    $headers = ['X-Forwarded-For' => '203.0.113.9'] + ($value === null ? [] : ['CF-Connecting-IP' => $value]);

    expect(ClientIp::for(proxiedRequest('10.0.0.5', $headers)))->toBe('203.0.113.9');
})->with(['missing' => null, 'not an IP' => 'not-an-ip', 'script' => '<script>alert(1)</script>', 'empty' => '']);

test('without a client address (console, queue, scheduler) there is no IP', function () {
    trustProxies(null);

    expect(ClientIp::for(Request::create('/', 'GET', server: ['REMOTE_ADDR' => ''])))->toBeNull();
});

test('configuring trusted proxies is application-wide, so request()->ip() sees the real client', function () {
    trustProxies('127.0.0.1, 10.0.0.0/8');
    Route::get('/_test/ip', fn (Request $request) => $request->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_test/ip')->assertContent('203.0.113.9');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_test/ip')->assertContent('198.51.100.7');
});

test('the address is the subscriber\'s for guest requests and their own, never for staff or impersonation', function () {
    trustProxies(null);
    $subscriber = User::factory()->create();
    $staff = User::factory()->create(['is_staff' => true]);
    $request = proxiedRequest('198.51.100.7');

    expect(ClientIp::forActivity($subscriber, $request))->toBe('198.51.100.7');

    $this->actingAs($subscriber);
    expect(ClientIp::forActivity($subscriber, $request))->toBe('198.51.100.7');

    $this->actingAs($staff);
    expect(ClientIp::forActivity($subscriber, $request))->toBeNull();

    $this->actingAs($subscriber);
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));
    expect(ClientIp::forActivity($subscriber, $request))->toBeNull();
});

test('a real sign-in behind a trusted proxy alerts with the forwarded client address', function () {
    Queue::fake();
    trustProxies('10.0.0.0/8');
    ActivityAlertRule::factory()->create(['scope' => 'all_subscribers', 'activity_types' => [ActivityAlertType::SignedIn->value], 'is_active' => true]);
    $user = User::factory()->create();
    Route::middleware('web')->get('/_test/sign-in', function () use ($user) {
        Auth::login($user);

        return 'ok';
    });

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_test/sign-in')->assertOk();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->userId === $user->id && $job->ipAddress === '203.0.113.9');
});

test('a real sign-in from an untrusted peer alerts with the connecting address, not a forged header', function () {
    Queue::fake();
    trustProxies('10.0.0.0/8');
    ActivityAlertRule::factory()->create(['scope' => 'all_subscribers', 'activity_types' => [ActivityAlertType::SignedIn->value], 'is_active' => true]);
    $user = User::factory()->create();
    Route::middleware('web')->get('/_test/sign-in', function () use ($user) {
        Auth::login($user);

        return 'ok';
    });

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withHeader('X-Forwarded-For', '203.0.113.9')->get('/_test/sign-in')->assertOk();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->ipAddress === '198.51.100.7');
});

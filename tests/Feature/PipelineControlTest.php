<?php

use App\Filament\Admin\Pages\PipelineControl;
use App\Models\User;
use App\Support\Pipeline\PipelineHeartbeat;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Http::preventStrayRequests();
});

test('the start action is visible and the stop action is hidden when nwws is not running', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionVisible('start')
        ->assertActionHidden('stop');
});

test('the stop action is visible and the start action is hidden when nwws is running', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionHidden('start')
        ->assertActionVisible('stop');
});

test('calling the start action starts the nwws container and notifies success', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/start' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('start')
        ->assertNotified('NWWS start succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/start'));
});

test('calling the restart action restarts the nwws container and notifies success', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/restart' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('restart')
        ->assertNotified('NWWS restart succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/restart'));
});

test('a failed docker call shows a failure notification instead of erroring', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)
        ->callAction('start')
        ->assertNotified('Failed to start NWWS');
});

test('calling the stop action stops the nwws container and notifies success', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stop')
        ->assertNotified('NWWS stop succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/stop'));
});

test('a failed stop shows a failure notification instead of erroring', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stop')
        ->assertNotified('Failed to stop NWWS');
});

test('the page degrades gracefully when the docker status check fails', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => []], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSuccessful()
        ->assertSee('Unknown')
        ->assertActionVisible('start')
        ->assertActionHidden('stop');
});

test('the status shown after a successful action reflects the new state, not a stale cached read', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::sequence()
            ->push(['State' => ['Status' => 'running']], 200)
            ->push(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stop')
        ->assertSee('Exited')
        ->assertActionVisible('start')
        ->assertActionHidden('stop');
});

test('the hidden action is genuinely absent from the rendered page, not just disabled', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertDontSeeHtml("mountAction('start')")
        ->assertSeeHtml("mountAction('stop')");
});

test('the emwin http backup row shows its docker status and start is hidden while it runs', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSee('EMWIN HTTP backup')
        ->assertDontSee('Status unknown')
        ->assertSee('Running')
        ->assertActionHidden('startEmwinHttp')
        ->assertActionVisible('stopEmwinHttp');
});

test('the emwin http start action is visible and stop hidden when it is not running', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/start' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->assertActionVisible('startEmwinHttp')
        ->assertActionHidden('stopEmwinHttp')
        ->callAction('startEmwinHttp')
        ->assertNotified('EMWIN HTTP start succeeded');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin-http/start'));
});

test('the emwin http stop and restart actions call the emwin http container and notify', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/stop' => Http::response('', 204),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/restart' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stopEmwinHttp')->assertNotified('EMWIN HTTP stop succeeded')
        ->callAction('restartEmwinHttp')->assertNotified('EMWIN HTTP restart succeeded');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin-http/stop'));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin-http/restart'));
});

test('the emwin http row last product comes from its own heartbeat', function () {
    Http::fake(['docker-socket-proxy:2375/*' => Http::response(['State' => ['Status' => 'running']], 200)]);
    Cache::forget('pipeline.heartbeat.emwin');
    Cache::forget('pipeline.heartbeat.emwin_http');

    expect(substr_count(livewire(PipelineControl::class)->html(), 'Last product: never'))->toBe(2);

    PipelineHeartbeat::record('emwin_http');

    expect(substr_count(livewire(PipelineControl::class)->html(), 'Last product: never'))->toBe(1);
});

test('the emwin byteblaster row shows its docker status and start is hidden while it runs', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSee('EMWIN Byteblaster')
        ->assertSee('Running')
        ->assertActionHidden('startEmwin')
        ->assertActionVisible('stopEmwin');
});

test('the emwin stop and restart actions call the emwin container and notify', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/stop' => Http::response('', 204),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/restart' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stopEmwin')->assertNotified('EMWIN stop succeeded')
        ->callAction('restartEmwin')->assertNotified('EMWIN restart succeeded');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin/stop'));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin/restart'));
});

test('the emwin start action starts the emwin container when it is stopped', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/start' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->assertActionVisible('startEmwin')
        ->callAction('startEmwin')
        ->assertNotified('EMWIN start succeeded');
});

test('a failed emwin docker call notifies a failure instead of erroring', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/stop' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)->callAction('stopEmwin')->assertNotified('Failed to stop EMWIN');
});

test('the emwin status after an action is not a stale cached read', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::sequence()
            ->push(['State' => ['Status' => 'running']], 200)
            ->push(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/stop' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stopEmwin')
        ->assertSee('Exited')
        ->assertActionVisible('startEmwin')
        ->assertActionHidden('stopEmwin');
});

test('the emwin row shows the last product received from the heartbeat', function () {
    Http::fake(['docker-socket-proxy:2375/*' => Http::response(['State' => ['Status' => 'running']], 200)]);
    PipelineHeartbeat::record('emwin');

    expect(substr_count(livewire(PipelineControl::class)->html(), 'Last product: never'))->toBe(1);
});

test('the emwin row shows never when no product has been received', function () {
    Http::fake(['docker-socket-proxy:2375/*' => Http::response(['State' => ['Status' => 'running']], 200)]);
    Cache::forget('pipeline.heartbeat.emwin');

    livewire(PipelineControl::class)->assertSee('Last product: never');
});

test('a missing emwin container degrades to Not found without breaking the nwws row', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response([], 404),
    ]);

    livewire(PipelineControl::class)->assertSuccessful()->assertSee('Not found')->assertActionVisible('stop');
});

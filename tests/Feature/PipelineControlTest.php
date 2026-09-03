<?php

use App\Filament\Admin\Pages\PipelineControl;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the start action is visible and the stop action is hidden when nwws is not running', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionVisible('start')
        ->assertActionHidden('stop');
});

test('the stop action is visible and the start action is hidden when nwws is running', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionHidden('start')
        ->assertActionVisible('stop');
});

test('calling the start action starts the nwws container and notifies success', function () {
    Http::fake([
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
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)
        ->callAction('start')
        ->assertNotified('Failed to start NWWS');
});

test('calling the stop action stops the nwws container and notifies success', function () {
    Http::fake([
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
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stop')
        ->assertNotified('Failed to stop NWWS');
});

test('the page degrades gracefully when the docker status check fails', function () {
    Http::fake([
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
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertDontSeeHtml("mountAction('start')")
        ->assertSeeHtml("mountAction('stop')");
});

test('the emwin rows remain static placeholders', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSee('EMWIN Byteblaster')
        ->assertSee('EMWIN HTTP backup')
        ->assertSee('Status unknown');
});

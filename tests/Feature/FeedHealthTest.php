<?php

use App\Enums\PipelineFeed;
use App\Support\Pipeline\FeedHealth;
use App\Support\Pipeline\PipelineSummary;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-09-21 12:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('docker state maps to the expected health state', function (string $docker, ?string $health, string $state, string $color) {
    $result = FeedHealth::evaluate($docker, $health, now(), 15);

    expect($result->state)->toBe($state)->and($result->color)->toBe($color);
})->with([
    'unreachable' => ['unknown', null, 'unknown', 'warning'],
    'missing' => ['not_found', null, 'not_found', 'gray'],
    'exited' => ['exited', null, 'stopped', 'gray'],
    'dead' => ['dead', null, 'down', 'danger'],
    'restarting' => ['restarting', null, 'restarting', 'warning'],
    'paused' => ['paused', null, 'restarting', 'warning'],
    'created' => ['created', null, 'restarting', 'warning'],
    'unhealthy' => ['running', 'unhealthy', 'unhealthy', 'danger'],
    'starting' => ['running', 'starting', 'starting', 'warning'],
    'healthy with healthcheck' => ['running', 'healthy', 'healthy', 'success'],
    'healthy without healthcheck' => ['running', null, 'healthy', 'success'],
    'unrecognised docker status' => ['removing', null, 'unknown', 'warning'],
]);

test('a running feed with no product ever is stale', function () {
    expect(FeedHealth::evaluate('running', null, null, 15))->state->toBe('stale')->label->toBe('Stale');
});

test('staleness is judged at the threshold boundary', function () {
    expect(FeedHealth::evaluate('running', null, now()->subMinutes(15), 15)->state)->toBe('healthy')
        ->and(FeedHealth::evaluate('running', null, now()->subMinutes(15)->subSecond(), 15)->state)->toBe('stale');
});

test('unhealthy beats stale and a stopped feed is never stale', function () {
    expect(FeedHealth::evaluate('running', 'unhealthy', null, 15)->state)->toBe('unhealthy')
        ->and(FeedHealth::evaluate('exited', null, null, 15)->state)->toBe('stopped');
});

test('the feed enum exposes labels, containers, roles and configured thresholds', function () {
    expect(PipelineFeed::Emwin->label())->toBe('EMWIN Byteblaster')
        ->and(PipelineFeed::EmwinHttp->container())->toBe('wxnotify-emwin-http')
        ->and(PipelineFeed::Nwws->container())->toBe('wxnotify-nwws')
        ->and(PipelineFeed::Emwin->container())->toBe('wxnotify-emwin-byteblaster')
        ->and(PipelineFeed::Nwws->isPrimary())->toBeTrue()
        ->and(PipelineFeed::EmwinHttp->isPrimary())->toBeFalse()
        ->and(PipelineFeed::Nwws->staleMinutes())->toBe(15)
        ->and(PipelineFeed::Emwin->staleMinutes())->toBe(15)
        ->and(PipelineFeed::EmwinHttp->staleMinutes())->toBe(30);
});

function healthMap(string $nwws, string $emwin, string $http): array
{
    $make = fn (string $state) => match ($state) {
        'healthy' => FeedHealth::evaluate('running', null, now(), 15),
        'stale' => FeedHealth::evaluate('running', null, null, 15),
        'unhealthy' => FeedHealth::evaluate('running', 'unhealthy', now(), 15),
        'stopped' => FeedHealth::evaluate('exited', null, null, 15),
    };

    return ['nwws' => $make($nwws), 'emwin' => $make($emwin), 'emwin_http' => $make($http)];
}

test('the summary is green when every feed is healthy', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'healthy'));

    expect($summary->color)->toBe('success')->and($summary->headline)->toBe('All feeds healthy')->and($summary->notes)->toBe([]);
});

test('a stopped backup is a note and does not change the color', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'stopped'));

    expect($summary->color)->toBe('success')
        ->and($summary->headline)->toBe('All running feeds healthy')
        ->and($summary->notes)->toBe(['EMWIN HTTP backup is stopped']);
});

test('a backup whose container is not found is noted as not deployed', function () {
    $health = healthMap('healthy', 'healthy', 'healthy');
    $health['emwin_http'] = FeedHealth::evaluate('not_found', null, null, 15);

    $summary = PipelineSummary::evaluate($health);

    expect($summary->color)->toBe('success')->and($summary->notes)->toBe(['EMWIN HTTP backup is not deployed']);
});

test('a stale feed makes the summary amber and names the feed', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'stale'));

    expect($summary->color)->toBe('warning')->and($summary->headline)->toBe('Needs attention: EMWIN HTTP backup (Stale)');
});

test('an unhealthy feed or a stopped primary makes the summary red', function () {
    expect(PipelineSummary::evaluate(healthMap('unhealthy', 'healthy', 'healthy'))->color)->toBe('danger')
        ->and(PipelineSummary::evaluate(healthMap('healthy', 'stopped', 'healthy'))->color)->toBe('danger')
        ->and(PipelineSummary::evaluate(healthMap('healthy', 'stopped', 'healthy'))->headline)->toBe('Needs attention: EMWIN Byteblaster (Stopped)');
});

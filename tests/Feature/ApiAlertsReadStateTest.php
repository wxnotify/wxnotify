<?php

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->rule = AlertRule::factory()->for($this->user)->create();

    $this->pushRow = fn (array $overrides = [], ?User $for = null) => DeliveryLog::factory()->for($for ?? $this->user)->create([
        'alert_rule_id' => $this->rule->id,
        'ingested_product_id' => IngestedProduct::factory()->create()->id,
        'category' => 'weather_alerts',
        'subject' => 'Tornado Warning',
        'channel_type' => 'mobile_push',
        'status' => 'success',
        'read_at' => null,
        ...$overrides,
    ]);
});

/**
 * @param  array<string, mixed>  $data
 */
function readStateRequest($test, string $method, string $uri, ?string $bearer = null, array $data = [])
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    return ($bearer === null ? $test : $test->withToken($bearer))->json($method, $uri, $data);
}

function readStateCutoff(CarbonInterface $at): string
{
    return $at->copy()->utc()->format('Y-m-d\\TH:i:s.u\\Z');
}

test('the feed lists read and unread alerts with the unread count in meta', function () {
    ($this->pushRow)(['dispatched_at' => now()->subMinutes(2)]);
    ($this->pushRow)(['dispatched_at' => now()->subMinute(), 'read_at' => now()]);

    $response = readStateRequest($this, 'GET', '/api/v1/alerts', $this->token)->assertOk();

    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.1.read', false)
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('meta.next_cursor', null);
});

test('every feed page reports the server time as as_of with microseconds', function () {
    $this->travelTo(now()->startOfSecond()->addMicroseconds(123456));
    ($this->pushRow)(['dispatched_at' => now()->subMinutes(2)]);
    ($this->pushRow)(['dispatched_at' => now()->subMinute()]);

    $first = readStateRequest($this, 'GET', '/api/v1/alerts?limit=1', $this->token)->assertOk();
    $next = readStateRequest($this, 'GET', '/api/v1/alerts?limit=1&before='.urlencode($first->json('meta.next_cursor')), $this->token)->assertOk();

    expect($first->json('meta.as_of'))->toBe(readStateCutoff(now()))->toEndWith('.123456Z')
        ->and($next->json('meta.as_of'))->toBe(readStateCutoff(now()));
});

test('mark all with a cutoff reads only rows created before it and counts the newer ones', function () {
    $through = readStateCutoff(now());
    $older = ($this->pushRow)(['created_at' => now()->subMinute()]);
    $sameSecond = ($this->pushRow)(['created_at' => now()]);
    $newer = ($this->pushRow)(['created_at' => now()->addSeconds(30)]);
    $theirs = ($this->pushRow)(['created_at' => now()->subMinute()], User::factory()->create());

    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $this->token, ['through' => $through])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 2]]);

    expect($older->fresh()->read_at)->not->toBeNull()
        ->and($sameSecond->fresh()->read_at)->toBeNull()
        ->and($newer->fresh()->read_at)->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
});

test('mark all without a cutoff still reads rows created after the list loaded', function () {
    $newer = ($this->pushRow)(['created_at' => now()->addMinute()]);

    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $this->token, ['through' => null])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 0]]);

    expect($newer->fresh()->read_at)->not->toBeNull();
});

test('mark all refuses a malformed cutoff or one too far in the future', function (Closure $through) {
    $row = ($this->pushRow)(['created_at' => now()->subMinute()]);

    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $this->token, ['through' => $through()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('through');

    expect($row->fresh()->read_at)->toBeNull();
})->with([
    'not a date' => fn () => fn () => 'not-a-date',
    'an array' => fn () => fn () => ['2026-10-20'],
    'beyond the five-minute bound' => fn () => fn () => readStateCutoff(now()->addMinutes(6)),
]);

test('mark all accepts a cutoff slightly ahead of the server clock', function () {
    $row = ($this->pushRow)(['created_at' => now()->subMinute()]);

    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $this->token, ['through' => readStateCutoff(now()->addMinutes(4))])
        ->assertOk();

    expect($row->fresh()->read_at)->not->toBeNull();
});

test('opening reads one alert and returns the new count', function () {
    $first = ($this->pushRow)();
    ($this->pushRow)();

    readStateRequest($this, 'POST', "/api/v1/alerts/{$first->id}/read", $this->token)
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 1]]);

    expect($first->fresh()->read_at)->not->toBeNull();
    readStateRequest($this, 'GET', "/api/v1/alerts/{$first->id}", $this->token)->assertJsonPath('data.read', true);
});

test('marking an alert that got a new push row between list and tap reads its new rows too', function () {
    $first = ($this->pushRow)();
    $later = ($this->pushRow)([
        'ingested_product_id' => $first->ingested_product_id,
        'dispatched_at' => now()->addMinute(),
    ]);

    readStateRequest($this, 'POST', "/api/v1/alerts/{$first->id}/read", $this->token)
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($first->fresh()->read_at)->not->toBeNull()->and($later->fresh()->read_at)->not->toBeNull();
});

test('reading twice is idempotent and keeps the first read time', function () {
    $alert = ($this->pushRow)();

    readStateRequest($this, 'POST', "/api/v1/alerts/{$alert->id}/read", $this->token)->assertOk();
    $readAt = $alert->fresh()->read_at;

    $this->travel(10)->minutes();

    readStateRequest($this, 'POST', "/api/v1/alerts/{$alert->id}/read", $this->token)
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($alert->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

test('another user\'s, an email-only and an unknown alert are not found', function () {
    $theirs = ($this->pushRow)([], User::factory()->create());
    $emailOnly = ($this->pushRow)(['channel_type' => 'email']);

    foreach ([$theirs->id, $emailOnly->id, 999999] as $id) {
        readStateRequest($this, 'POST', "/api/v1/alerts/{$id}/read", $this->token)->assertNotFound();
    }

    expect($theirs->fresh()->read_at)->toBeNull()->and($emailOnly->fresh()->read_at)->toBeNull();
});

test('mark all reads every unread alert of the caller only', function () {
    $mine = [($this->pushRow)(), ($this->pushRow)()];
    $theirs = ($this->pushRow)([], User::factory()->create());

    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $this->token)
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 0]]);

    foreach ($mine as $row) {
        expect($row->fresh()->read_at)->not->toBeNull();
    }
    expect($theirs->fresh()->read_at)->toBeNull();
});

test('channels and has_failure ignore email and failed rows', function () {
    $push = ($this->pushRow)();
    ($this->pushRow)(['ingested_product_id' => $push->ingested_product_id, 'channel_type' => 'email', 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);
    ($this->pushRow)(['ingested_product_id' => $push->ingested_product_id, 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);

    $response = readStateRequest($this, 'GET', '/api/v1/alerts', $this->token)->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.channels')
        ->assertJsonPath('data.0.channels.0', ['type' => 'mobile_push', 'type_label' => 'Mobile app', 'status' => 'success'])
        ->assertJsonPath('data.0.has_failure', false);
});

test('a beacon token is refused', function () {
    $beacon = $this->user->createToken('Beacon', ['location'])->plainTextToken;
    $alert = ($this->pushRow)();

    readStateRequest($this, 'POST', "/api/v1/alerts/{$alert->id}/read", $beacon)->assertForbidden();
    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $beacon)->assertForbidden();
    readStateRequest($this, 'POST', '/api/v1/alerts/read-all', $beacon, ['through' => readStateCutoff(now())])->assertForbidden();

    expect($alert->fresh()->read_at)->toBeNull();
});

test('the read responses carry no body or error detail', function () {
    $alert = ($this->pushRow)(['error_detail' => 'SECRET-DETAIL']);

    foreach ([["/api/v1/alerts/{$alert->id}/read", 'POST'], ['/api/v1/alerts/read-all', 'POST'], ["/api/v1/alerts/{$alert->id}", 'GET'], ['/api/v1/alerts', 'GET']] as [$uri, $method]) {
        $content = readStateRequest($this, $method, $uri, $this->token)->assertOk()->getContent();

        expect($content)->not->toContain('SECRET');
    }

    readStateRequest($this, 'POST', "/api/v1/alerts/{$alert->id}/read", $this->token)->assertExactJson(['data' => ['unread_count' => 0]]);
});

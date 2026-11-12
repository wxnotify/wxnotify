<?php

use App\Models\DeliveryLog;
use App\Models\OutlookReportRun;
use App\Models\User;
use App\Support\OutlookReportPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
});

function outlookReportFixture(User $user, bool $old = false, bool $writeFiles = true): array
{
    $run = OutlookReportRun::factory()->for($user)->withMaps()->when($old, fn ($factory) => $factory->old())->create();
    $log = DeliveryLog::factory()->for($user)->create([
        'alert_rule_id' => $run->alert_rule_id,
        'ingested_product_id' => null,
        'batch_key' => $run->batch_key,
        'category' => 'outlook_report',
        'subject' => 'Severe Weather Outlook',
        'channel_type' => 'mobile_push',
        'status' => 'success',
    ]);

    if ($writeFiles) {
        Storage::disk('local')->put($run->maps['day1']['path'], 'PNGBYTES');
        Storage::disk('local')->put($run->maps['day48']['path'], 'GIFBYTES');
    }

    return [$run, $log];
}

test('the detail carries the presented report with signed map urls that serve the stored bytes', function () {
    [$run, $log] = outlookReportFixture($this->user);

    $response = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk();
    $report = $response->json('data.outlook_report');
    $expected = OutlookReportPresenter::present($run->payload);

    expect(array_column($report['days'], 'heading'))->toBe(array_column($expected['days'], 'heading'))
        ->and(array_column($report['days'], 'levels'))->toBe(array_column($expected['days'], 'levels'))
        ->and($report['days'][0]['locations'])->toBe($expected['days'][0]['locations'])
        ->and($report['note'])->toBe(OutlookReportPresenter::NOTE)
        ->and($report['source_url'])->toBe(OutlookReportPresenter::SPC_URL)
        ->and($report['maps_expired'])->toBeFalse()
        ->and($report['days'][1]['map_url'])->toBeNull()
        ->and($report['extended_map_url'])->not->toBeNull();

    $this->get($report['days'][0]['map_url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=3600, private');

    $this->get($report['extended_map_url'])->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect(json_encode($response->json(), JSON_UNESCAPED_SLASHES))
        ->not->toContain($run->maps['day1']['path'])
        ->not->toContain($run->maps['day48']['path'])
        ->not->toContain('error_detail')
        ->not->toContain('"path"');
});

test('an unsigned, tampered or expired map link is refused', function () {
    [$run] = outlookReportFixture($this->user);
    $signed = URL::temporarySignedRoute('api.v1.outlook-maps.show', now()->addHour(), ['batchKey' => $run->batch_key, 'key' => 'day1']);

    $this->get(route('api.v1.outlook-maps.show', ['batchKey' => $run->batch_key, 'key' => 'day1']))->assertForbidden();
    $this->get(str_replace('day1', 'day48', $signed))->assertForbidden();

    $this->travel(61)->minutes();
    $this->get($signed)->assertForbidden();
});

test('the map route is 404 for a key never stored, a key the route constraint rejects, an unknown run, a missing file and an expired run', function () {
    [$run] = outlookReportFixture($this->user);
    $link = fn (string $batchKey, string $key) => URL::temporarySignedRoute('api.v1.outlook-maps.show', now()->addHour(), ['batchKey' => $batchKey, 'key' => $key]);

    $this->get($link($run->batch_key, 'day2'))->assertNotFound();
    $this->get($link($run->batch_key, 'bogus'))->assertNotFound();
    $this->get($link('unknown-batch', 'day1'))->assertNotFound();

    Storage::disk('local')->delete($run->maps['day1']['path']);
    $this->get($link($run->batch_key, 'day1'))->assertNotFound();

    [$old] = outlookReportFixture($this->user, old: true);
    $this->get($link($old->batch_key, 'day1'))->assertNotFound();
});

test('an old run keeps its text but has no map urls and says the maps expired', function () {
    [$run, $log] = outlookReportFixture($this->user, old: true);

    $report = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk()->json('data.outlook_report');

    expect($report['maps_expired'])->toBeTrue()
        ->and($report['days'][0]['levels'])->toBe(OutlookReportPresenter::present($run->payload)['days'][0]['levels'])
        ->and(array_filter(array_column($report['days'], 'map_url')))->toBe([])
        ->and($report['extended_map_url'])->toBeNull();
});

test('a map whose file is missing has a null url', function () {
    [, $log] = outlookReportFixture($this->user, writeFiles: false);

    $report = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk()->json('data.outlook_report');

    expect($report['days'][0]['map_url'])->toBeNull()
        ->and($report['maps_expired'])->toBeFalse();
});

test('an alert without a run or of another category has a null outlook report', function () {
    $brief = DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => null, 'batch_key' => 'brief-1', 'category' => 'daily_brief',
        'channel_type' => 'mobile_push', 'status' => 'success',
    ]);
    $preChange = DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => null, 'batch_key' => 'no-run', 'category' => 'outlook_report',
        'channel_type' => 'mobile_push', 'status' => 'success',
    ]);
    $run = OutlookReportRun::factory()->for($this->user)->create();
    $otherCategory = DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => null, 'batch_key' => $run->batch_key, 'category' => 'daily_brief',
        'channel_type' => 'mobile_push', 'status' => 'success',
    ]);

    foreach ([$brief, $preChange, $otherCategory] as $log) {
        $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.outlook_report', null);
    }
});

test('another users alert is 404 and another users run with the same batch key is not shown', function () {
    $other = User::factory()->create();
    [$run, $otherLog] = outlookReportFixture($other);

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$otherLog->id}")->assertNotFound();

    $mine = DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => null, 'batch_key' => $run->batch_key, 'category' => 'outlook_report',
        'channel_type' => 'mobile_push', 'status' => 'success',
    ]);

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.outlook_report', null);
});

test('the map route uses its own named limiter, keyed per client address and set to 60 a minute', function () {
    $route = app('router')->getRoutes()->getByName('api.v1.outlook-maps.show');

    expect($route->gatherMiddleware())->toContain('throttle:app-outlook-map')->not->toContain('throttle:60,1');

    $limit = RateLimiter::limiter('app-outlook-map')(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

    expect($limit->maxAttempts)->toBe(60)
        ->and($limit->key)->toBe('app-outlook-map:203.0.113.9');
});

test('serves a stored map from the configured disk through the signed link', function () {
    config(['filesystems.outlook_maps_disk' => 'maps']);
    Storage::fake('maps');
    [$run, $log] = outlookReportFixture($this->user, writeFiles: false);
    Storage::disk('maps')->put($run->maps['day1']['path'], 'MAPDISKBYTES');

    $report = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk()->json('data.outlook_report');

    expect($report['days'][0]['map_url'])->not->toBeNull();
    $this->get($report['days'][0]['map_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(Storage::disk('maps')->get($run->maps['day1']['path']))->toBe('MAPDISKBYTES');
});

test('an unreachable disk reads as no map, not an error', function () {
    $root = storage_path('framework/testing/disks/unreachable-maps');
    File::ensureDirectoryExists($root);
    config([
        'filesystems.disks.unreachable' => ['driver' => 'local', 'root' => $root, 'throw' => false],
        'filesystems.outlook_maps_disk' => 'unreachable',
    ]);
    [$run, $log] = outlookReportFixture($this->user);

    $report = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk()->json('data.outlook_report');

    expect($report['days'][0]['map_url'])->toBeNull();

    $link = URL::temporarySignedRoute('api.v1.outlook-maps.show', now()->addHour(), ['batchKey' => $run->batch_key, 'key' => 'day1']);
    $this->get($link)->assertNotFound();
});

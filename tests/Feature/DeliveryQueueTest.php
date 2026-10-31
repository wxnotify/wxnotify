<?php

use App\Enums\ActivityAlertType;
use App\Jobs\DeliverScheduledAlert;
use App\Jobs\DeliverWeatherAlert;
use App\Jobs\PairOutlookAreasAfterDelay;
use App\Jobs\ProcessIngestedProduct;
use App\Jobs\PruneMobilePushChannel;
use App\Jobs\RecordQueueHeartbeat;
use App\Jobs\ScanShapefileLayersJob;
use App\Jobs\SendActivityAlert;
use App\Mail\ChannelMessageMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Yaml\Yaml;

/**
 * A job that sends to a subscriber's channel goes on the `deliveries` queue, which the worker reads before
 * `default`, so an alert never waits behind a burst of products being processed. These tests queue onto
 * the real database driver, so the queue checked is the `jobs.queue` column the worker reads.
 */
beforeEach(function (): void {
    config(['queue.default' => 'database']);
});

/**
 * The queues the stack's worker reads, highest priority first (compose.yaml's `queue` service).
 */
const CHAN_WORKER_QUEUES = 'deliveries,default';

/**
 * The seconds in a compose duration such as `70s`.
 */
function chanComposeSeconds(string $duration): int
{
    expect($duration)->toMatch('/^\d+s$/');

    return (int) $duration;
}

/**
 * The value of a worker command's `--timeout=` option, 0 when it has none.
 */
function chanCommandTimeout(string $command): int
{
    preg_match('/--timeout=(\d+)/', $command, $timeout);

    return (int) ($timeout[1] ?? 0);
}

/**
 * @return array<string, mixed>
 */
function chanComposeService(string $file, string $service): array
{
    return Yaml::parseFile(base_path($file))['services'][$service];
}

test('a job that sends to a subscriber channel is queued on the deliveries queue', function (object $job) {
    dispatch($job);

    expect(DB::table('jobs')->sole()->queue)->toBe('deliveries');
})->with([
    'a weather alert' => fn (): DeliverWeatherAlert => new DeliverWeatherAlert(1, 2, 3),
    'a scheduled alert (daily summary, threshold, lightning)' => fn (): DeliverScheduledAlert => new DeliverScheduledAlert(1, 2, 'Title', 'Body'),
]);

test('processing, housekeeping and admin jobs stay on the default queue', function (object $job) {
    dispatch($job);

    expect(DB::table('jobs')->sole()->queue)->toBe('default');
})->with([
    'processing an ingested product' => fn (): ProcessIngestedProduct => new ProcessIngestedProduct(1),
    'the delayed outlook pairing check' => fn (): PairOutlookAreasAfterDelay => new PairOutlookAreasAfterDelay(1),
    'pruning a dead phone channel' => fn (): PruneMobilePushChannel => new PruneMobilePushChannel(1, 'hash'),
    'an admin activity alert' => fn (): SendActivityAlert => new SendActivityAlert(1, ActivityAlertType::SignedIn, 2, now()),
    'the queue heartbeat' => fn (): RecordQueueHeartbeat => new RecordQueueHeartbeat,
]);

test('the worker runs a queued delivery before an older job waiting on the default queue', function () {
    Mail::fake();
    $rule = AlertRule::factory()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();
    $rule->notificationChannels()->attach($channel);
    $olderProduct = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    $alertedProduct = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    ProcessIngestedProduct::dispatch($olderProduct->id);
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $alertedProduct->id);

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => CHAN_WORKER_QUEUES, '--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    expect(DeliveryLog::sole()->ingested_product_id)->toBe($alertedProduct->id)
        ->and(DB::table('jobs')->pluck('queue')->all())->toBe(['default']);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('the stack worker is a persistent worker that reads deliveries first, runs a job once and stops it before the queue would hand it out again', function () {
    $service = chanComposeService('compose.yaml', 'queue');
    $timeout = chanCommandTimeout($service['command']);

    expect($service['command'])->toStartWith('php artisan queue:work ')
        ->toContain('--queue='.CHAN_WORKER_QUEUES.' ')
        ->toContain('--tries=1 ')
        ->not->toContain('long')
        ->and($timeout)->toBeGreaterThan(0)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and(chanComposeSeconds($service['stop_grace_period']))->toBeGreaterThan($timeout);
});

test('the shapefile scan is queued on the long queue of its own connection', function () {
    $job = new ScanShapefileLayersJob(1);

    dispatch($job);

    expect($job->connection)->toBe('database_long')
        ->and(DB::table('jobs')->sole()->queue)->toBe('long');
});

test('without the database queue the shapefile scan keeps the default connection and queue', function () {
    config(['queue.default' => 'sync']);

    $job = new ScanShapefileLayersJob(1);

    expect($job->connection)->toBeNull()
        ->and($job->queue)->toBeNull();
});

test('the long connection shares the jobs table and hands a scan out again only after its timeout', function () {
    $long = config('queue.connections.database_long');

    expect($long['driver'])->toBe('database')
        ->and($long['table'])->toBe(config('queue.connections.database.table'))
        ->and($long['connection'])->toBe(config('queue.connections.database.connection'))
        ->and($long['queue'])->toBe('long')
        ->and($long['retry_after'])->toBeGreaterThan((new ScanShapefileLayersJob(1))->timeout);
});

test('the stack worker leaves a queued shapefile scan for its own worker', function () {
    dispatch(new ScanShapefileLayersJob(1));

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => CHAN_WORKER_QUEUES, '--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    expect(DB::table('jobs')->sole()->queue)->toBe('long');
});

test('the long worker reads only the long queue, runs a scan once, and lets it finish before the queue or a stop would cut it short', function () {
    $service = chanComposeService('compose.yaml', 'queue-long');
    $timeout = chanCommandTimeout($service['command']);

    expect($service['command'])->toStartWith('php artisan queue:work database_long ')
        ->toContain('--queue=long ')
        ->toContain('--tries=1 ')
        ->and($timeout)->toBeGreaterThanOrEqual((new ScanShapefileLayersJob(1))->timeout)
        ->toBeLessThan(config('queue.connections.database_long.retry_after'))
        ->and(chanComposeSeconds($service['stop_grace_period']))->toBeGreaterThan($timeout);
});

test('the no-restart development override reads the same queues in the same order and runs a job once', function () {
    $command = chanComposeService('compose.dev-listen.yaml', 'queue')['command'];

    expect($command)->toStartWith('php artisan queue:listen ')
        ->toContain('--queue='.CHAN_WORKER_QUEUES.' ')
        ->toContain('--tries=1');
});

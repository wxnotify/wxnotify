<?php

use App\Actions\Alerts\StoreOutlookReportRun;
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\OutlookReportRun;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

const ORS_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x00IEND\xaeB`\x82";
const ORS_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->credentials = fcmUseCredentials();
    Storage::fake('local');
    Carbon::setTestNow('2026-10-31 14:30:00');
});

afterEach(function () {
    @unlink($this->credentials['path']);
    Carbon::setTestNow();
});

/**
 * Fakes FCM, the SPC files (Day 1 enhanced, others quiet) and the four SPC graphics.
 *
 * @param  bool  $pushFails  FCM rejects the push
 */
function orsFakeSpc(bool $pushFails = false): void
{
    $headers = ['Content-Type' => 'application/geo+json', 'Last-Modified' => 'Sat, 31 Oct 2026 13:00:00 GMT'];
    $collection = fn (array $labels) => Http::response(json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-98.0, 35.0], [-96.0, 35.0], [-96.0, 37.0], [-98.0, 37.0], [-98.0, 35.0]]]],
            'properties' => ['LABEL' => $label, 'ISSUE_ISO' => '2026-10-31T13:00:00Z'],
        ], $labels),
    ]), 200, $headers);
    $base = 'www.spc.noaa.gov/products/';
    $files = [];

    foreach ([1, 2] as $day) {
        foreach (['cat', 'torn', 'hail', 'wind'] as $layer) {
            $files["{$base}outlook/day{$day}otlk_{$layer}.nolyr.geojson"] = $collection($day === 1 && $layer === 'cat' ? ['TSTM', 'SLGT', 'ENH'] : []);
        }
    }

    $files["{$base}outlook/day3otlk_cat.nolyr.geojson"] = $collection([]);
    $files["{$base}outlook/day3otlk_prob.nolyr.geojson"] = $collection([]);

    foreach ([4, 5, 6, 7, 8] as $day) {
        $files["{$base}exper/day4-8/day{$day}prob.nolyr.geojson"] = $collection([]);
    }

    $files["{$base}outlook/*"] = Http::response(ORS_PNG, 200, ['Content-Type' => 'image/png']);
    $files["{$base}exper/*"] = Http::response(ORS_GIF, 200, ['Content-Type' => 'image/gif']);

    fcmFakeGoogle($pushFails ? Http::response(['error' => ['status' => 'INTERNAL']], 500) : null);
    Http::fake($files);
}

/**
 * Dispatches the outlook report for a fresh paid rule on the given channels and runs the worker.
 *
 * @param  list<string>  $channels  'phone', 'phone2' or 'email'
 * @param  array<string, mixed>  $ruleAttributes
 */
function orsSend(array $channels = ['phone'], array $ruleAttributes = [], bool $pushFails = false): AlertRule
{
    orsFakeSpc($pushFails);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $location = Location::factory()->for($user)->create(['label' => 'Home', 'lat' => 35.9, 'lng' => -97.0]);
    $rule = AlertRule::factory()->outlookReport()->for($user)->locations($location)->create($ruleAttributes);

    foreach ($channels as $channel) {
        $rule->notificationChannels()->attach(match ($channel) {
            'phone' => NotificationChannel::factory()->for($user)->mobilePush('token-1')->create(),
            'phone2' => NotificationChannel::factory()->for($user)->mobilePush('token-2')->create(),
            'email' => NotificationChannel::factory()->for($user)->email()->create(),
        });
    }

    test()->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'deliveries,default', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    return $rule;
}

test('a successful push of an outlook run leaves one run with its maps, once for two phones', function () {
    $rule = orsSend(['phone', 'phone2']);

    $log = DeliveryLog::where('alert_rule_id', $rule->id)->get();
    $run = OutlookReportRun::sole();
    expect($log)->toHaveCount(2)
        ->and($log->pluck('batch_key')->unique()->all())->toBe([$run->batch_key])
        ->and($run->alert_rule_id)->toBe($rule->id)
        ->and($run->payload['days'][1]['available'])->toBeTrue()
        ->and(array_keys($run->maps))->toBe(['day1', 'day2', 'day3', 'day48'])
        ->and(Storage::disk('local')->allFiles('outlook-maps'))->toHaveCount(4);
});

test('a failed push stores no run', function () {
    $rule = orsSend(pushFails: true);

    expect(DeliveryLog::where('alert_rule_id', $rule->id)->sole()->status)->toBe('failure')
        ->and(OutlookReportRun::count())->toBe(0);
});

test('a Brief rule stores no run and no maps', function () {
    orsSend(ruleAttributes: ['message_body_style' => MessageBodyStyle::Brief]);

    expect(OutlookReportRun::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('an email channel alone stores no run', function () {
    orsSend(['email']);

    expect(OutlookReportRun::count())->toBe(0);
});

test('a job queued before the report existed stores nothing and does not throw', function () {
    orsFakeSpc();
    $rule = AlertRule::factory()->outlookReport()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->mobilePush('token-1')->create();
    $rule->notificationChannels()->attach($channel);

    $job = new DeliverScheduledAlert($rule->id, $channel->id, 'Subject', 'Body');
    $job->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::where('alert_rule_id', $rule->id)->sole()->status)->toBe('success')
        ->and(OutlookReportRun::count())->toBe(0);
});

test('a storage failure neither fails the job nor changes the delivery status', function () {
    $this->mock(StoreOutlookReportRun::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('disk full'));
    Log::spy();

    $rule = orsSend();

    expect(DeliveryLog::where('alert_rule_id', $rule->id)->sole()->status)->toBe('success')
        ->and(OutlookReportRun::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Outlook report run could not be stored', ['exception' => RuntimeException::class]);
});

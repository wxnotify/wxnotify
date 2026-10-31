<?php

use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

const OMB_SUBJECT = 'Severe Wx Report - 🟠 D1 | 🟡 D2 | 🟢 D3 | ⚪ D4 | 🟡 D5 | ⚪ D6 | ⚪ D7 | ⚪ D8';
const OMB_BRIEF = 'D1 🟠  D2 🟡  D3 🟢  D4-8 🟡';

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->credentials = fcmUseCredentials();
    Carbon::setTestNow('2026-10-31 14:30:00');
});

afterEach(function () {
    @unlink($this->credentials['path']);
    Carbon::setTestNow();
});

/**
 * @param  list<string>  $labels
 */
function ombCollection(array $labels): string
{
    return json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-98.0, 35.0], [-96.0, 35.0], [-96.0, 37.0], [-98.0, 37.0], [-98.0, 35.0]]]],
            'properties' => ['LABEL' => $label, 'ISSUE_ISO' => '2026-10-31T13:00:00Z'],
        ], $labels),
    ]);
}

/**
 * Fakes FCM and the SPC files with a different highest risk per period: Day 1 enhanced, Day 2 slight,
 * Day 3 marginal, Day 5 15% (Days 4, 6, 7 and 8 quiet).
 */
function ombFakeSpc(): void
{
    fcmFakeGoogle();
    $headers = ['Content-Type' => 'application/geo+json', 'Last-Modified' => 'Sat, 31 Oct 2026 13:00:00 GMT'];
    $response = fn (array $labels) => Http::response(ombCollection($labels), 200, $headers);
    $base = 'www.spc.noaa.gov/products/';
    $files = [];

    foreach ([1, 2] as $day) {
        foreach (['torn', 'hail', 'wind'] as $layer) {
            $files["{$base}outlook/day{$day}otlk_{$layer}.nolyr.geojson"] = $response([]);
        }
    }

    $files["{$base}outlook/day1otlk_cat.nolyr.geojson"] = $response(['TSTM', 'SLGT', 'ENH']);
    $files["{$base}outlook/day2otlk_cat.nolyr.geojson"] = $response(['TSTM', 'SLGT']);
    $files["{$base}outlook/day3otlk_cat.nolyr.geojson"] = $response(['TSTM', 'MRGL']);
    $files["{$base}outlook/day3otlk_prob.nolyr.geojson"] = $response([]);

    foreach ([4, 5, 6, 7, 8] as $day) {
        $files["{$base}exper/day4-8/day{$day}prob.nolyr.geojson"] = $response($day === 5 ? ['0.15'] : []);
    }

    Http::fake($files);
}

/**
 * Sends the outlook report for a fresh rule to one mobile push channel and runs the worker.
 *
 * @param  array<string, mixed>  $channelConfig
 * @return array{0: User, 1: AlertRule}
 */
function ombSend(array $channelConfig = []): array
{
    ombFakeSpc();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->for($user)->mobilePush('device-token-1')->create();

    if ($channelConfig !== []) {
        $channel->forceFill(['config' => [...$channel->config, ...$channelConfig]])->save();
    }

    $location = Location::factory()->for($user)->create(['label' => 'Home', 'lat' => 35.9, 'lng' => -97.0]);
    $rule = AlertRule::factory()->outlookReport()->for($user)->locations($location)->create(['name' => 'Outlook']);
    $rule->notificationChannels()->attach($channel);

    test()->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'deliveries,default', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    return [$user, $rule];
}

test('the Brief is pushed to the phone and listed in the app feed as an alert that opens the Alerts page', function () {
    [$user, $rule] = ombSend();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'messages:send')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification']['title'] === OMB_SUBJECT
        && $request['message']['notification']['body'] === OMB_BRIEF
        && ((array) $request['message']['data'])['url'] === '/');

    $log = DeliveryLog::where('alert_rule_id', $rule->id)->sole();
    expect($log->category)->toBe('outlook_report')
        ->and($log->channel_type)->toBe('mobile_push')
        ->and($log->status)->toBe('success')
        ->and($log->ingested_product_id)->toBeNull()
        ->and($log->batch_key)->not->toBeNull();

    $token = $user->createToken('iPhone', ['app'])->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/alerts')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $log->id)
        ->assertJsonPath('data.0.subject', OMB_SUBJECT)
        ->assertJsonPath('data.0.category', 'outlook_report')
        ->assertJsonPath('data.0.category_label', 'Severe Weather Outlook Report')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread_count', 1);
});

test('a phone that opens tap references gets the alert link of the run\'s batch', function () {
    [, $rule] = ombSend(['tap_ref' => true]);

    $log = DeliveryLog::where('alert_rule_id', $rule->id)->sole();
    expect($log->batch_key)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'messages:send')
        && $request['message']['notification']['body'] === OMB_BRIEF
        && ((array) $request['message']['data'])['url'] === 'wxnotify://alert/b.'.$log->batch_key);
});

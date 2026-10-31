<?php

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->credentials = fcmUseCredentials();
    Carbon::setTestNow('2026-10-31 14:30:00');
});

afterEach(function () {
    @unlink($this->credentials['path']);
    Carbon::setTestNow();
});

test('the Brief is pushed to the phone and listed in the app feed as an alert that opens the Alerts page', function () {
    $quiet = json_encode(['type' => 'FeatureCollection', 'features' => []]);
    fcmFakeGoogle();
    Http::fake(['www.spc.noaa.gov/*' => Http::response($quiet, 200, ['Content-Type' => 'application/geo+json'])]);

    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->mobilePush('device-token-1')->create();
    $location = Location::factory()->for($user)->create(['label' => 'Home', 'lat' => 35.9, 'lng' => -97.0]);
    $rule = AlertRule::factory()->outlookReport()->for($user)->locations($location)->create(['name' => 'Outlook']);
    $rule->notificationChannels()->attach($channel);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'deliveries,default', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);

    $subject = 'Severe Wx Report - ⚪ D1 | ⚪ D2 | ⚪ D3 | ⚪ D4 | ⚪ D5 | ⚪ D6 | ⚪ D7 | ⚪ D8';
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'messages:send')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification']['title'] === $subject
        && $request['message']['notification']['body'] === 'D1 ⚪  D2 ⚪  D3 ⚪  D4-8 ⚪'
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
        ->assertJsonPath('data.0.subject', $subject)
        ->assertJsonPath('data.0.category', 'outlook_report')
        ->assertJsonPath('data.0.category_label', 'Severe Weather Outlook Report')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread_count', 1);
});

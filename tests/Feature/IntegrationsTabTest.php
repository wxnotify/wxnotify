<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\IntegrationSettings;
use App\Support\Delivery\HeartbeatUrlGuard;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the page loads the integration defaults', function () {
    livewire(AppSettings::class)
        ->assertFormSet([
            'translation_enabled' => false,
            'short_urls_enabled' => false,
            'heartbeat_enabled' => false,
            'exception_reporting_enabled' => false,
            'archival_enabled' => false,
            'status_page_enabled' => false,
            'email_relay_enabled' => true,
        ]);
});

test('an endpoint field is hidden until its integration is enabled', function () {
    livewire(AppSettings::class)
        ->assertFormFieldHidden('translation_endpoint')
        ->fillForm(['translation_enabled' => true])
        ->assertFormFieldVisible('translation_endpoint');
});

test('saving an enabled integration stores its endpoint', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'translation_enabled' => true,
            'translation_endpoint' => 'https://translate.example.test',
            'status_page_enabled' => true,
            'status_page_url' => 'http://status.internal:3001',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->translation_enabled)->toBeTrue()
        ->and($settings->translation_endpoint)->toBe('https://translate.example.test')
        ->and($settings->status_page_enabled)->toBeTrue()
        ->and($settings->status_page_url)->toBe('http://status.internal:3001');
});

test('an enabled integration requires its endpoint', function (string $toggle, string $field) {
    livewire(AppSettings::class)
        ->fillForm([$toggle => true, $field => null])
        ->call('save')
        ->assertHasFormErrors([$field => 'required']);
})->with([
    'translation' => ['translation_enabled', 'translation_endpoint'],
    'short urls' => ['short_urls_enabled', 'short_urls_endpoint'],
    'status page' => ['status_page_enabled', 'status_page_url'],
    'archival endpoint' => ['archival_enabled', 'archival_endpoint'],
    'archival bucket' => ['archival_enabled', 'archival_bucket'],
    'archival provider' => ['archival_enabled', 'archival_provider'],
]);

test('only http and https URLs are accepted', function (string $value) {
    livewire(AppSettings::class)
        ->fillForm(['translation_enabled' => true, 'translation_endpoint' => $value])
        ->call('save')
        ->assertHasFormErrors(['translation_endpoint']);
})->with(['ftp://example.test', 'javascript:alert(1)', 'not a url', 'file:///etc/passwd']);

test('a disabled integration keeps its stored endpoint and is not required', function () {
    $settings = app(IntegrationSettings::class);
    $settings->translation_enabled = true;
    $settings->translation_endpoint = 'https://translate.example.test';
    $settings->save();

    livewire(AppSettings::class)
        ->fillForm(['translation_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->translation_enabled)->toBeFalse()
        ->and($settings->translation_endpoint)->toBe('https://translate.example.test');
});

test('archival stores its provider, endpoint and bucket', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'archival_enabled' => true,
            'archival_provider' => 'b2',
            'archival_endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'archival_bucket' => 'wxnotify-products',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->archival_provider)->toBe('b2')
        ->and($settings->archival_bucket)->toBe('wxnotify-products');
});

test('an unknown archival provider is rejected while archival is enabled', function () {
    livewire(AppSettings::class)
        ->fillForm(['archival_enabled' => true, 'archival_provider' => 'bogus'])
        ->call('save')
        ->assertHasFormErrors(['archival_provider']);
});

test('the email mailer choices come from the configured mailers and exclude the internal ones', function () {
    $options = collect(livewire(AppSettings::class)->instance()->getForm('form')->getComponent('email_mailer')?->getOptions() ?? []);

    expect($options->keys()->all())->toContain('smtp')
        ->not->toContain('array')->not->toContain('log')->not->toContain('failover')->not->toContain('roundrobin');
});

test('an unknown mailer and a bad from address are rejected', function () {
    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => 'bogus'])
        ->call('save')
        ->assertHasFormErrors(['email_mailer']);

    livewire(AppSettings::class)
        ->fillForm(['email_from_address' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors(['email_from_address']);
});

test('saving email overrides stores them and empty values become null', function () {
    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => 'smtp', 'email_from_address' => 'alerts@example.test', 'email_from_name' => 'WxNotify Alerts'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->email_mailer)->toBe('smtp')
        ->and($settings->email_from_address)->toBe('alerts@example.test')
        ->and($settings->email_from_name)->toBe('WxNotify Alerts');

    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => null, 'email_from_address' => '', 'email_from_name' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->email_mailer)->toBeNull()
        ->and($settings->email_from_address)->toBeNull()
        ->and($settings->email_from_name)->toBeNull();
});

test('a saved integration change is written to the audit log', function () {
    livewire(AppSettings::class)
        ->fillForm(['exception_reporting_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()
        ->where('log_name', 'settings')
        ->where('description', 'Updated integrations settings')
        ->sole();

    expect($activity->properties['changes'])->toBe(['exception_reporting_enabled' => ['old' => false, 'new' => true]]);
});

test('the heartbeat ping url fields are hidden until heartbeat is enabled', function () {
    livewire(AppSettings::class)
        ->assertFormFieldHidden('heartbeat_nwws_url')
        ->assertFormFieldHidden('heartbeat_emwin_url')
        ->assertFormFieldHidden('heartbeat_emwin_http_url')
        ->fillForm(['heartbeat_enabled' => true])
        ->assertFormFieldVisible('heartbeat_nwws_url')
        ->assertFormFieldVisible('heartbeat_emwin_url')
        ->assertFormFieldVisible('heartbeat_emwin_http_url');
});

test('heartbeat urls are optional and saved per feed, with blank saved as null', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'heartbeat_enabled' => true,
            'heartbeat_nwws_url' => 'https://hc.example.test/ping/aaaa',
            'heartbeat_emwin_url' => '',
            'heartbeat_emwin_http_url' => 'http://healthchecks.internal:8000/ping/cccc',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->heartbeat_enabled)->toBeTrue()
        ->and($settings->heartbeat_nwws_url)->toBe('https://hc.example.test/ping/aaaa')
        ->and($settings->heartbeat_emwin_url)->toBeNull()
        ->and($settings->heartbeat_emwin_http_url)->toBe('http://healthchecks.internal:8000/ping/cccc');
});

test('heartbeat can be enabled with no urls at all', function () {
    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_nwws_url' => null, 'heartbeat_emwin_url' => null, 'heartbeat_emwin_http_url' => null])
        ->call('save')
        ->assertHasNoFormErrors();
});

test('only http and https heartbeat urls are accepted', function (string $field, string $value) {
    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, $field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);
})->with([
    'nwws ftp' => ['heartbeat_nwws_url', 'ftp://hc.example.test/ping/x'],
    'emwin not a url' => ['heartbeat_emwin_url', 'not a url'],
    'http backup javascript' => ['heartbeat_emwin_http_url', 'javascript:alert(1)'],
]);

test('turning heartbeat off keeps the stored ping urls', function () {
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/aaaa';
    $settings->heartbeat_emwin_http_url = 'https://hc.example.test/ping/cccc';
    $settings->save();

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->heartbeat_enabled)->toBeFalse()
        ->and($settings->heartbeat_nwws_url)->toBe('https://hc.example.test/ping/aaaa')
        ->and($settings->heartbeat_emwin_http_url)->toBe('https://hc.example.test/ping/cccc');
});

test('each heartbeat ping url field has a test button, enabled once the field has a value', function (string $field) {
    $action = TestAction::make("testHeartbeat_{$field}")->schemaComponent($field, schema: 'form');

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, $field => null])
        ->assertActionDisabled($action)
        ->fillForm([$field => 'https://hc.example.test/ping/x'])
        ->assertActionEnabled($action);
})->with(['heartbeat_nwws_url', 'heartbeat_emwin_url', 'heartbeat_emwin_http_url']);

test('the other endpoint fields have no test button', function () {
    livewire(AppSettings::class)
        ->fillForm(['translation_enabled' => true, 'translation_endpoint' => 'https://t.example.test'])
        ->assertActionDoesNotExist(TestAction::make('testHeartbeat_translation_endpoint')->schemaComponent('translation_endpoint', schema: 'form'));
});

test('testing a heartbeat url sends one GET to the typed, unsaved value and reports the status', function () {
    app()->instance(HeartbeatUrlGuard::class, new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));
    Http::fake(['hc.example.test/*' => Http::response('OK', 200)]);

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_emwin_url' => 'https://hc.example.test/ping/typed-secret'])
        ->callAction(TestAction::make('testHeartbeat_heartbeat_emwin_url')->schemaComponent('heartbeat_emwin_url', schema: 'form'))
        ->assertNotified('Test ping sent');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://hc.example.test/ping/typed-secret');
    expect(app(IntegrationSettings::class)->heartbeat_emwin_url)->toBeNull();
});

test('a failed test ping shows a danger notification and never the URL', function () {
    app()->instance(HeartbeatUrlGuard::class, new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));
    Http::fake(['hc.example.test/*' => Http::response('no', 404)]);

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_nwws_url' => 'https://hc.example.test/ping/typed-secret'])
        ->callAction(TestAction::make('testHeartbeat_heartbeat_nwws_url')->schemaComponent('heartbeat_nwws_url', schema: 'form'))
        ->assertNotified('Test ping failed');
});

test('a test ping notification states the status and never repeats the URL', function () {
    app()->instance(HeartbeatUrlGuard::class, new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));
    Http::fake(['hc.example.test/*' => Http::response('no', 404)]);

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_nwws_url' => 'https://hc.example.test/ping/typed-secret'])
        ->callAction(TestAction::make('testHeartbeat_heartbeat_nwws_url')->schemaComponent('heartbeat_nwws_url', schema: 'form'));

    $bodies = sentNotificationBodies();

    expect($bodies)->toContain('HTTP 404')->not->toContain('typed-secret')->not->toContain('hc.example.test');
});

test('a malformed value is reported without sending anything', function () {
    Http::fake();

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_nwws_url' => 'not a url'])
        ->callAction(TestAction::make('testHeartbeat_heartbeat_nwws_url')->schemaComponent('heartbeat_nwws_url', schema: 'form'))
        ->assertNotified('Test ping failed');

    Http::assertNothingSent();
});

test('reverse geocoding is on by default, its endpoint field follows the toggle, and both save', function () {
    livewire(AppSettings::class)
        ->assertFormSet(['reverse_geocoding_enabled' => true])
        ->assertFormFieldVisible('reverse_geocoding_endpoint')
        ->fillForm(['reverse_geocoding_enabled' => false])
        ->assertFormFieldHidden('reverse_geocoding_endpoint')
        ->fillForm(['reverse_geocoding_enabled' => true, 'reverse_geocoding_endpoint' => 'https://geo.example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class);
    expect($settings->reverse_geocoding_enabled)->toBeTrue()->and($settings->reverse_geocoding_endpoint)->toBe('https://geo.example.test');

    livewire(AppSettings::class)
        ->fillForm(['reverse_geocoding_endpoint' => ''])
        ->call('save');

    expect(app(IntegrationSettings::class)->reverse_geocoding_endpoint)->toBeNull();
});

test('the reverse geocoding endpoint must be an http(s) URL', function () {
    livewire(AppSettings::class)
        ->fillForm(['reverse_geocoding_enabled' => true, 'reverse_geocoding_endpoint' => 'not a url'])
        ->call('save')
        ->assertHasFormErrors(['reverse_geocoding_endpoint']);
});

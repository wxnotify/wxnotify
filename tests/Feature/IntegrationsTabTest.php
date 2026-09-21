<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;
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
    'heartbeat' => ['heartbeat_enabled', 'heartbeat_endpoint'],
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

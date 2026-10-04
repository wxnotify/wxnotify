<?php

use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\WebhookUrlGuard;
use Filament\Actions\Testing\TestAction;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

// chanFakeWebhookEndpoint() and chanFakePauser() live in tests/Pest.php.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // Every hostname resolves to a public address unless a test says otherwise.
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakePauser();
});

test('the list page explains what it\'s for', function () {
    livewire(ListNotificationChannels::class)
        ->assertSee('Where your alerts actually get delivered');
});

test('the Destination column captions each value with what it is', function () {
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'label' => 'Work Pushover',
        'apprise_url' => 'pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321',
    ]);
    NotificationChannel::factory()->for($this->user)->email('first@example.com')->create(['label' => 'My Email']);
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'telegram', 'label' => 'Family Chat', 'apprise_url' => 'tgram://123%3AABC-token/-100123456',
    ]);

    livewire(ListNotificationChannels::class)
        ->assertSee('••••1234')
        ->assertSee('Pushover user key')
        ->assertSee('first@example.com')
        ->assertSee('Email address')
        ->assertSee('-100123456')
        ->assertSee('Telegram chat ID')
        ->assertDontSee('ABC-token');
});

test('a destination value that cannot be read shows no caption', function () {
    NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover', 'label' => 'Broken Pushover', 'apprise_url' => 'not-a-pushover-url',
    ]);

    livewire(ListNotificationChannels::class)
        ->assertSee('—')
        ->assertDontSee('Pushover user key');
});

test('the Destination column tells apart two channels of the same type', function () {
    // Regression: the table previously showed only Label/Type/Verified, so
    // two Email channels with different labels were indistinguishable --
    // there was no way to tell which address either one actually used.
    NotificationChannel::factory()->for($this->user)->email('first@example.com')->create();
    NotificationChannel::factory()->for($this->user)->email('second@example.com')->create();

    livewire(ListNotificationChannels::class)
        ->assertSee('first@example.com')
        ->assertSee('second@example.com');
});

test('the Destination column never renders a webhook\'s signing secret', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 'super-secret-value')->create();

    livewire(ListNotificationChannels::class)
        ->assertSee('hooks.example.com')
        ->assertDontSee('super-secret-value');

    expect($channel->config['secret'])->toBe('super-secret-value');
});

/**
 * The flat option fields the webhook edit form submits, so each test only has to
 * name what it is actually changing.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanEditFormData(array $overrides = []): array
{
    return [
        'label' => 'Hook',
        'url' => 'https://hooks.example.com/wx',
        'method' => 'POST',
        'sign' => true,
        'secret' => 's3cret',
        'verify_ssl' => true,
        'use_timestamp' => true,
        'tries' => 3,
        'timeout' => 5,
        'headers' => [],
        'use_proxy' => false,
        ...$overrides,
    ];
}

test('entering the correct code on an unverified channel verifies it', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => $channel->confirmation_code,
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('entering the wrong code does not verify the channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => 'wrong-code',
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeFalse();
});

test('entering an expired code does not verify the channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create([
        'confirmation_code_expires_at' => now()->subMinute(),
    ]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => $channel->confirmation_code,
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeFalse();
});

test('the verify action is not visible for an already-verified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->assertActionHidden(TestAction::make('verify')->table($channel));
});

test('the verify action is visible for an unverified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->assertActionVisible(TestAction::make('verify')->table($channel));
});

test('the send test action is hidden for an unverified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->assertActionHidden(TestAction::make('sendTest')->table($channel));
});

test('the send test action is visible for a verified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->assertActionVisible(TestAction::make('sendTest')->table($channel));
});

test('sending a test notification calls apprise with the channel url and notifies success', function () {
    Http::fake(['*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->create(['apprise_url' => 'discord://123456789/token/']);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->assertNotified();

    Http::assertSent(fn ($request) => $request['urls'] === 'discord://123456789/token/'
        && str_contains($request['body'], $channel->label));
});

test('a failed test send notifies failure without changing the channel', function () {
    Http::fake(['*' => Http::response(status: 500)]);
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('an unreachable apprise api notifies failure instead of throwing', function () {
    Http::fake(fn () => throw new ConnectionException('Could not connect to Apprise'));
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->assertNotified();
});

test('editing a channel changes its label', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old label']);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: [
            'label' => 'New label',
        ])
        ->assertHasNoActionErrors();

    expect($channel->fresh()->label)->toBe('New label');
});

test('the label is required and cannot be cleared out', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old label']);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: [
            'label' => '',
        ])
        ->assertHasActionErrors(['label' => 'required']);

    expect($channel->fresh()->label)->toBe('Old label');
});

test('the edit form fills a legacy webhook config with the option defaults and reveals the secret', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaStateSet([
            'url' => 'https://hooks.example.com/wx',
            'secret' => 's3cret',
            'method' => 'POST',
            'sign' => true,
            'verify_ssl' => true,
            'use_timestamp' => true,
            'tries' => 3,
            'timeout' => 5,
            'headers' => [],
            'use_proxy' => false,
            'proxy_url' => null,
        ]);
});

test('the edit form fills the stored options of a fully configured webhook channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook()->create(['config' => [
        'url' => 'https://hooks.example.com/wx',
        'secret' => 's3cret',
        'method' => 'PATCH',
        'sign' => false,
        'verify_ssl' => false,
        'use_timestamp' => false,
        'tries' => 5,
        'timeout' => 20,
        'headers' => ['X-Api-Key' => 'abc123'],
        'use_proxy' => true,
        'proxy_url' => 'http://proxy.example.com:3128',
    ]]);

    livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaStateSet([
            'method' => 'PATCH',
            'tries' => 5,
            'timeout' => 20,
            'headers' => ['X-Api-Key' => 'abc123'],
            'use_proxy' => true,
            'proxy_url' => 'http://proxy.example.com:3128',
        ]);
});

test('the webhook option fields and the payload description are hidden for a non-webhook channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel));

    foreach (['url', 'secret', 'method', 'headers', 'use_proxy'] as $field) {
        $component->assertFormFieldHidden($field);
    }

    $component->assertSchemaComponentHidden('payload_description');
});

test('the edit form explains the webhook payload for a webhook channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentVisible('payload_description');
});

test('editing a webhook channel label leaves the rest of its config untouched', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook()->create(['config' => [
        'url' => 'https://hooks.example.com/wx',
        'secret' => 's3cret',
        'headers' => ['X-Api-Key' => 'abc123'],
    ]]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData([
            'label' => 'Renamed hook',
            'headers' => ['X-Api-Key' => 'abc123'],
        ]))
        ->assertHasNoActionErrors();

    expect($channel->fresh()->label)->toBe('Renamed hook')
        ->and($channel->fresh()->config)->toMatchArray([
            'url' => 'https://hooks.example.com/wx',
            'secret' => 's3cret',
            'headers' => ['X-Api-Key' => 'abc123'],
        ]);
});

test('editing a webhook channel changes only the options that were submitted', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData([
            'method' => 'PATCH',
            'verify_ssl' => false,
            'tries' => 5,
            'timeout' => 20,
            'headers' => ['X-Api-Key' => 'abc123'],
        ]))
        ->assertHasNoActionErrors();

    expect($channel->fresh()->config)->toMatchArray([
        'url' => 'https://hooks.example.com/wx',
        'secret' => 's3cret',
        'method' => 'PATCH',
        'verify_ssl' => false,
        'tries' => 5,
        'timeout' => 20,
        'headers' => ['X-Api-Key' => 'abc123'],
    ]);
});

test('turning signing off on the edit form keeps the stored secret', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData(['sign' => false]))
        ->assertHasNoActionErrors();

    expect($channel->fresh()->config['sign'])->toBeFalse()
        ->and($channel->fresh()->config['secret'])->toBe('s3cret');
});

test('a private target url on the edit form is a form error and nothing is saved', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData(['url' => 'https://evil.example.com/wx']))
        ->assertHasActionErrors(['url']);

    expect($channel->fresh()->config['url'])->toBe('https://hooks.example.com/wx');
});

test('a private proxy url on the edit form is a form error and nothing is saved', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData([
            'use_proxy' => true,
            'proxy_url' => 'http://169.254.169.254:80',
        ]))
        ->assertHasActionErrors(['proxy_url']);

    expect($channel->fresh()->config)->not->toHaveKey('proxy_url');
});

test('a reserved extra header on the edit form is a form error and nothing is saved', function () {
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData([
            'headers' => ['Transfer-Encoding' => 'chunked'],
        ]))
        ->assertHasActionErrors(['headers']);

    expect($channel->fresh()->config)->not->toHaveKey('headers');
});

test('changing the webhook url unverifies the channel and re-sends a confirmation code', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();
    $originalCode = $channel->confirmation_code;

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData(['url' => 'https://hooks.example.com/new']))
        ->assertHasNoActionErrors();

    $channel->refresh();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($channel->config['url'])->toBe('https://hooks.example.com/new')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBe($originalCode)
        ->and($payload['code'])->toBe($channel->confirmation_code)
        ->and((string) $history[0]['request']->getUri())->toBe('https://hooks.example.com/new');
});

test('leaving the webhook url alone keeps the channel verified and sends nothing', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData(['label' => 'Renamed']))
        ->assertHasNoActionErrors();

    expect($channel->fresh()->isVerified())->toBeTrue()->and(count($history))->toBe(0);
});

test('a url change whose confirmation cannot be delivered leaves the channel unverified, not deleted', function () {
    // A confirmation is a single attempt even at tries=5, so this edit can never
    // hold the request longer than one timeout.
    $history = chanFakeWebhookEndpoint([new Response(500)]);
    $channel = NotificationChannel::factory()->for($this->user)->webhook('https://hooks.example.com/wx', 's3cret')->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: chanEditFormData(['url' => 'https://hooks.example.com/new', 'tries' => 5]))
        ->assertHasNoActionErrors();

    expect(count($history))->toBe(1)
        ->and(NotificationChannel::whereKey($channel->getKey())->exists())->toBeTrue()
        ->and($channel->fresh()->isVerified())->toBeFalse()
        ->and($channel->fresh()->config['url'])->toBe('https://hooks.example.com/new');
});

test('editing a non-webhook channel still never writes its config', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'discord',
        'config' => ['keep' => 'me'],
    ]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), data: ['label' => 'Renamed discord'])
        ->assertHasNoActionErrors();

    expect($channel->fresh()->label)->toBe('Renamed discord')
        ->and($channel->fresh()->config)->toBe(['keep' => 'me']);
});

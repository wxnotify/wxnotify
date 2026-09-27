<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\WebhookUrlGuard;
use Filament\Forms\Components\TextInput;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

// goToConnectChannelStep() and chanFakeWebhookEndpoint() live in tests/Pest.php.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    chanFakePauser();
});

test('completing the wizard creates a pending channel and dispatches a code', function () {
    Http::fake([
        '*/notify/*' => Http::response(status: 200),
    ]);

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->channel_type)->toBe('ntfy')
        ->and($channel->apprise_url)->toBe('ntfy://wxnotify-test-topic')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
});

test('a dispatch failure because Apprise is unreachable is handled gracefully', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);

    // Not assertWizardCurrentStep(2): Wizard::nextStep() optimistically bumps
    // its runtime currentStepIndex before running afterValidation, and never
    // reverts it when a Halt is caught — only the client-side step transition
    // (dispatched after the try/catch, so never reached here) is actually
    // skipped. Verified for real in a browser instead: the "Confirm" step
    // never becomes visible and the danger notification renders.
    $component->assertNotified('Couldn\'t send your confirmation code');

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0)
        ->and($component->get('data.channel_id'))->toBeNull();
});

test('entering the correct code verifies the channel', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => $channel->confirmation_code])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('entering the wrong code does not verify the channel', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => 'wrong-code'])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeFalse();
});

test('a Free subscriber cannot pick a second no-cost channel type', function () {
    NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    expect($this->user->fresh()->canAddChannel('slack'))->toBeFalse();
});

test('completing the wizard for pushover stores the pover url', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'pushover']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->fillForm(['user_key' => 'abcdefghijklmnopqrstuvwxyz1234', 'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->channel_type)->toBe('pushover')
        ->and($channel->apprise_url)->toBe('pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321');
});

test('the pushover options show the emergency fields only for emergency priority and the sound has a play button', function () {
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'pushover']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertFormFieldHidden('retry_interval')
        ->assertFormFieldHidden('expire')
        ->assertFormSet(['priority' => '0', 'sound' => 'pushover'])
        ->assertFormComponentActionExists('sound', 'playPushoverSound')
        ->fillForm(['priority' => '2'])
        ->assertFormFieldVisible('retry_interval')
        ->assertFormFieldVisible('expire')
        ->assertFormSet(['retry_interval' => 900, 'expire' => 3600]);
});

test('completing the wizard for pushover with options stores them in the pover url', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'pushover']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm([
        'user_key' => 'abcdefghijklmnopqrstuvwxyz1234',
        'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321',
        'devices' => "iphone\nipad",
        'priority' => '2',
        'sound' => 'siren',
        'retry_interval' => 60,
        'expire' => 7200,
    ]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    expect(NotificationChannel::where('user_id', $this->user->id)->sole()->apprise_url)
        ->toBe('pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321/iphone/ipad?priority=2&sound=siren&interval=60&expire=7200');
});

test('completing the wizard for teams stores the workflows url', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'msteams']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=SIGNATUREabc-_123',
    ]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    expect(NotificationChannel::where('user_id', $this->user->id)->sole()->apprise_url)
        ->toBe('workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123');
});

test('the webhook wizard step 2 opens with every option at its default and a fresh revealable secret', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);

    $component->assertFormSet([
        'method' => 'POST',
        'sign' => true,
        'verify_ssl' => true,
        'use_timestamp' => true,
        'tries' => 3,
        'timeout' => 5,
        'headers' => [],
        'use_proxy' => false,
    ]);

    expect($component->get('data.secret'))->toMatch('/^[0-9a-f]{64}$/');

    $component->assertFormFieldExists('secret', fn (TextInput $field): bool => $field->isPassword() && $field->isPasswordRevealable());
});

test('the wizard explains the webhook payload, and only for the webhook type', function () {
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    $component->assertSchemaComponentVisible('payload_description');

    $component->fillForm(['channel_type' => 'ntfy']);
    $component->assertSchemaComponentDoesNotExist('payload_description');
});

test('the signing secret is hidden when signing is off and the proxy url is hidden until the proxy is on', function () {
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);

    $component->assertFormFieldVisible('secret')->assertFormFieldHidden('proxy_url');

    $component->fillForm(['sign' => false, 'use_proxy' => true]);

    $component->assertFormFieldHidden('secret')->assertFormFieldVisible('proxy_url');
});

test('the webhook wizard stores the chosen options and sends the code to the endpoint', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $history = chanFakeWebhookEndpoint([new Response(200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm([
        'url' => 'https://hooks.example.com/wx',
        'method' => 'PUT',
        'use_timestamp' => false,
        'tries' => 2,
        'timeout' => 9,
        'headers' => ['X-Api-Key' => 'abc123'],
    ]);
    $secret = $component->get('data.secret');
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($channel->channel_type)->toBe('webhook')->and($channel->apprise_url)->toBeNull()
        ->and($channel->config)->toMatchArray([
            'url' => 'https://hooks.example.com/wx',
            'method' => 'PUT',
            'sign' => true,
            'secret' => $secret,
            'verify_ssl' => true,
            'use_timestamp' => false,
            'tries' => 2,
            'timeout' => 9,
            'headers' => ['X-Api-Key' => 'abc123'],
            'use_proxy' => false,
            'proxy_url' => null,
        ])
        ->and($payload['type'])->toBe('confirmation')->and($payload['code'])->toBe($channel->confirmation_code)
        ->and($history[0]['request']->getMethod())->toBe('PUT')
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('abc123');

    // The secret is now shown in step 2, so the confirm step no longer carries one.
    expect($component->get('data.webhook_secret'))->toBeNull();
});

test('the wizard refuses a private proxy url as a form error', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakeWebhookEndpoint([new Response(200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm([
        'url' => 'https://hooks.example.com/wx',
        'use_proxy' => true,
        'proxy_url' => 'http://127.0.0.1:3128',
    ]);
    goToConnectChannelStep($component, 1);

    $component->assertHasFormErrors(['proxy_url']);
    expect(NotificationChannel::count())->toBe(0);
});

test('the wizard refuses a reserved extra header as a form error', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakeWebhookEndpoint([new Response(200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm([
        'url' => 'https://hooks.example.com/wx',
        'headers' => ['Content-Type' => 'text/x-secret-value'],
    ]);
    goToConnectChannelStep($component, 1);

    $component->assertHasFormErrors(['headers']);
    expect(NotificationChannel::count())->toBe(0);
});

test('a webhook whose endpoint rejects the confirmation message is not left behind', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    // One response, not several: a confirmation is deliberately a single
    // attempt, so the wizard submit can never outlast one timeout.
    $history = chanFakeWebhookEndpoint([new Response(500)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['url' => 'https://hooks.example.com/wx', 'tries' => 5]);
    goToConnectChannelStep($component, 1);

    expect(NotificationChannel::count())->toBe(0)->and(count($history))->toBe(1);
});

test('creating a non-webhook channel after a webhook one clears the signing secret from the wizard', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakeWebhookEndpoint([new Response(200)]);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['url' => 'https://hooks.example.com/wx']);
    goToConnectChannelStep($component, 1);

    expect($component->get('data.secret'))->not->toBeNull();

    // Back to step 1 in the same wizard instance, then pick a non-webhook type.
    $component->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);

    expect($component->get('data.secret'))->toBeNull()
        ->and($component->get('data.url'))->toBeNull();
});

test('the web push wizard creates a channel from a browser subscription and pushes the confirmation code', function () {
    chanConfigureVapid();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $sent = chanFakePushService();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webpush']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['subscription' => chanPushSubscriptionJson()]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    expect($channel->channel_type)->toBe('webpush')->and($channel->apprise_url)->toBeNull()
        ->and(json_decode($sent[0]['payload'], true)['body'])->toContain($channel->confirmation_code);
});

/**
 * Drive the Web Push wizard from the picker through step 2 with the given
 * browser PushSubscription JSON.
 */
function chanRunWebPushWizard(string $subscriptionJson): void
{
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webpush']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['subscription' => $subscriptionJson]);
    goToConnectChannelStep($component, 1);
}

test('re-running the web push wizard in the same browser refreshes the one channel instead of duplicating it', function () {
    // The subscribe component deliberately reuses the browser's live
    // PushSubscription, so a second run returns the same endpoint with freshly
    // exported keys. Two channels on one endpoint would deliver every alert twice.
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    chanConfigureVapid();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $sent = chanFakePushService();

    chanRunWebPushWizard(chanPushSubscriptionJson());

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    $firstCode = $channel->confirmation_code;
    $channel->forceFill(['verified_at' => now()])->save();

    chanRunWebPushWizard(json_encode([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('d', 22)],
    ]));

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(1);

    $refreshed = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($refreshed->id)->toBe($channel->id)
        ->and($refreshed->config['public_key'])->toBe(str_repeat('C', 87))
        ->and($refreshed->config['auth_token'])->toBe(str_repeat('d', 22))
        ->and($refreshed->isVerified())->toBeFalse()
        ->and($refreshed->confirmation_code)->not->toBe($firstCode)
        ->and(count($sent))->toBe(2)
        ->and(json_decode($sent[1]['payload'], true)['body'])->toContain($refreshed->confirmation_code);
});

test('a web push subscription from a different browser still gets its own channel', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    chanConfigureVapid();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakePushService();

    chanRunWebPushWizard(chanPushSubscriptionJson());
    chanRunWebPushWizard(chanPushSubscriptionJson('https://fcm.googleapis.com/fcm/send/different999'));

    $endpoints = NotificationChannel::where('user_id', $this->user->id)->get()
        ->map(fn (NotificationChannel $channel) => $channel->config['endpoint'])
        ->all();

    expect($endpoints)->toEqualCanonicalizing([
        'https://fcm.googleapis.com/fcm/send/abc123',
        'https://fcm.googleapis.com/fcm/send/different999',
    ]);
});

test('the web push wizard rejects an invalid subscription without creating a channel', function () {
    chanConfigureVapid();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));
    chanFakePushService();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webpush']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['subscription' => chanPushSubscriptionJson('https://push.evil.example/x')]);
    goToConnectChannelStep($component, 1);

    $component->assertNotified('That browser subscription is not valid.');
    expect(NotificationChannel::count())->toBe(0);
});

test('completing the wizard for email stores the address in config and mails the confirmation code', function () {
    Mail::fake();
    Http::fake();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'email']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['to' => 'subscriber@example.com']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->channel_type)->toBe('email')
        ->and($channel->config)->toBe(['to' => 'subscriber@example.com'])
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->confirmation_code)->not->toBeNull();

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && str_contains($mail->messageBody, $channel->confirmation_code));
    Http::assertNothingSent();
});

<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

// goToConnectChannelStep() and chanFakeWebhookEndpoint() live in tests/Pest.php.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
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

test('the webhook wizard creates a channel with a secret, shows it on the confirm step, and sends the code to the endpoint', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $history = chanFakeWebhookEndpoint([new Response(200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['url' => 'https://hooks.example.com/wx']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($channel->channel_type)->toBe('webhook')->and($channel->apprise_url)->toBeNull()
        ->and($payload['type'])->toBe('confirmation')->and($payload['code'])->toBe($channel->confirmation_code);
    $component->assertFormSet(['webhook_secret' => $channel->config['secret']]);
});

test('a webhook whose endpoint rejects the confirmation message is not left behind', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    chanFakeWebhookEndpoint([new Response(500)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'webhook']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['url' => 'https://hooks.example.com/wx']);
    goToConnectChannelStep($component, 1);

    expect(NotificationChannel::count())->toBe(0);
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

    expect($component->get('data.webhook_secret'))->not->toBeNull();

    // Back to step 1 in the same wizard instance, then pick a non-webhook type.
    $component->fillForm(['channel_type' => 'ntfy']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['topic' => 'wxnotify-test-topic']);
    goToConnectChannelStep($component, 1);

    expect($component->get('data.webhook_secret'))->toBeNull();
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

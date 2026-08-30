<?php

use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Wizard::getCurrentStepIndex() lives on a plain runtime property, not
 * Livewire's persisted state — every Testable::call()/fillForm() rehydrates
 * the page fresh and resets it to the start step. goToNextWizardStep()
 * trusts that (reset) value to pick which step it's advancing, so a second
 * chained call silently re-validates step 0 instead of the step the test is
 * actually on. Driving nextStep() with an explicit index sidesteps it.
 */
function goToConnectChannelStep(Testable $component, int $fromIndex): Testable
{
    $schema = $component->instance()->{$component->instance()->getDefaultTestingSchemaName()};
    $wizard = $schema->getComponent(fn ($c) => $c instanceof Wizard);

    return $component->call('callSchemaComponentMethod', $wizard->getKey(), 'nextStep', [$fromIndex]);
}

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

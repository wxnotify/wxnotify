<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

/**
 * Runs one setup through the dashboard wizard and through the API, each as its own Pro subscriber
 * (so the Free slot never interferes), and returns what each stored, or null when it refused.
 *
 * @param  array<string, mixed>  $input
 * @return array{dashboard: ?array<string, mixed>, api: ?array<string, mixed>}
 */
function channelParityOutcome($test, string $slug, array $input): array
{
    $dashboardUser = User::factory()->create(['tier_override' => Tier::Pro]);
    $test->actingAs($dashboardUser);
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => $slug]);
    goToConnectChannelStep($component, 0);
    $component->fillForm($input);
    goToConnectChannelStep($component, 1);

    $apiUser = User::factory()->create(['tier_override' => Tier::Pro]);
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    app('auth')->forgetGuards();
    $test->withToken($token)->postJson('/api/v1/channels', ['channel_type' => $slug, ...$input]);

    $stored = fn (User $user): ?array => ($channel = NotificationChannel::where('user_id', $user->id)->first())
        ? ['apprise_url' => $channel->apprise_url, 'config' => $channel->config, 'label' => $channel->label]
        : null;

    return ['dashboard' => $stored($dashboardUser), 'api' => $stored($apiUser)];
}

beforeEach(function () {
    chanFakePauser();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    Mail::fake();
});

test('the dashboard wizard and the API accept the same setups and store the same destination', function (string $slug, array $valid) {
    // The ntfy custom server is resolved by ChatServerGuard; no name resolves unless faked.
    chanFakeChatDns(['ntfy.example.com' => ['93.184.216.34']]);

    foreach ($valid as $input) {
        $outcome = channelParityOutcome($this, $slug, $input);

        expect($outcome['dashboard'])->not->toBeNull(json_encode($input))
            ->and($outcome['api'])->toBe($outcome['dashboard']);
    }
})->with([
    'email' => ['email', [['to' => 'pat@example.com']]],
    'ntfy' => ['ntfy', [['topic' => 'wx-abc_123'], ['topic' => 'wx', 'server' => 'https://ntfy.example.com']]],
    'discord' => ['discord', [['webhook_url' => 'https://discord.com/api/webhooks/123456789/abcDEF-token_123']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def', 'chat_id' => '-1001234']]],
    'teams' => ['msteams', [['webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=abc_DEF-123']]],
]);

test('the dashboard wizard and the API refuse the same setups', function (string $slug, array $invalid) {
    foreach ($invalid as $input) {
        $outcome = channelParityOutcome($this, $slug, $input);

        expect($outcome['dashboard'])->toBeNull(json_encode($input))
            ->and($outcome['api'])->toBeNull(json_encode($input));
    }
})->with([
    'email' => ['email', [['to' => 'not-an-email']]],
    'ntfy' => ['ntfy', [['topic' => 'has space'], ['topic' => 'wx', 'server' => 'notaurl']]],
    'discord' => ['discord', [['webhook_url' => 'https://example.com/hook'], ['webhook_url' => 'http://discord.com/api/webhooks/1/a']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T0/B0']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def']]],
    'teams' => ['msteams', [['webhook_url' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers?sig=abc']]],
]);

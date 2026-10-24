<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\ChannelDispatcher;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanEnableWhatsApp(), chanFakeChatDns() and chanChatFormSubmit() live in tests/Pest.php.

const CHAN_BATCH_SLUGS = ['google_chat', 'mattermost', 'rocketchat', 'matrix', 'whatsapp', 'pagerduty', 'bluesky'];

const CHAN_BATCH_PUBLIC_IP = '93.184.216.34';

beforeEach(function () {
    chanEnableWhatsApp();
    chanFakeChatDns(['chat.example.com' => [CHAN_BATCH_PUBLIC_IP], 'matrix.example.org' => [CHAN_BATCH_PUBLIC_IP]]);
    chanFakePauser();
    chanFakeBlueskyAccount();
});

/**
 * Step 2 data for each messaging type, and the Apprise scheme its confirmation code goes out on.
 *
 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
 */
function chanBatchWizardCases(): array
{
    return [
        'google_chat' => ['google_chat', [
            'webhook_url' => 'https://chat.googleapis.com/v1/spaces/AAAAbatch01/messages?key=AIzaSyBatchKey-1&token=BatchToken%3D',
        ], 'gchat://'],
        'mattermost' => ['mattermost', [
            'webhook_url' => 'https://chat.example.com/hooks/h7kq3m9xw5e8rbt4y6uzi3o5pa',
        ], 'mmosts://'],
        'rocketchat' => ['rocketchat', [
            'webhook_url' => 'https://chat.example.com/hooks/aB3dE5fG7hJ9kL2mN/Zx9Cv8Bn7Mq6Wr5Ty4Ui3Op2As1Df0Gh',
        ], 'rockets://'],
        'matrix' => ['matrix', [
            'homeserver' => 'https://matrix.example.org',
            'access_token' => 'syt_d3hub3RpZnk_AbCdEfGhIjKlMnOpQrSt_1a2B3c',
            'room' => '!AbCdEfGhIjKlMn:example.org',
        ], 'matrixs://'],
        'whatsapp' => ['whatsapp', [
            'access_token' => 'EAABsbCS1iHgBO7ZBx9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJk',
            'phone_number_id' => '123456789012',
            'template' => 'wx_alert',
            'template_language' => 'es_MX',
            'to' => '+52 55 1234 5678',
            'consent' => true,
        ], 'whatsapp://'],
        'pagerduty' => ['pagerduty', [
            'integration_key' => 'e93facc04764012d7bfb002500d5d1a6',
            'severity' => 'error',
        ], 'pagerduty://'],
        'bluesky' => ['bluesky', [
            'handle' => 'wxdesk.bsky.social',
            'app_password' => 'k7m2-q4xz-p9hw-3tab',
            'consent' => true,
        ], 'bsky://'],
    ];
}

/**
 * One verified channel of each messaging type for the user.
 *
 * @return list<NotificationChannel>
 */
function chanBatchChannelsFor(User $user): array
{
    return [
        NotificationChannel::factory()->for($user)->googleChat()->create(),
        NotificationChannel::factory()->for($user)->mattermost()->create(),
        NotificationChannel::factory()->for($user)->rocketChat()->create(),
        NotificationChannel::factory()->for($user)->matrix()->create(),
        NotificationChannel::factory()->for($user)->whatsApp()->create(),
        NotificationChannel::factory()->for($user)->pagerDuty()->create(),
        NotificationChannel::factory()->for($user)->bluesky()->create(),
    ];
}

test('a Plus subscriber connects each of the seven types through the wizard', function (string $slug, array $data, string $scheme) {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    chanChatFormSubmit($slug, $data)->assertHasNoFormErrors();

    $channel = NotificationChannel::sole();

    expect($channel->channel_type)->toBe($slug)
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->isVerified())->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request['urls'], $scheme)
        && str_contains($request['body'], $channel->confirmation_code));

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => $channel->confirmation_code])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeTrue();
})->with(fn (): array => chanBatchWizardCases());

test('a Free subscriber sees all seven disabled in the picker and is refused on a forced step', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tiles = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    foreach (CHAN_BATCH_SLUGS as $slug) {
        $label = app(ChannelTypeRegistry::class)->find($slug)->label();
        expect($tiles->getOptions())->toHaveKey($slug)
            ->and($tiles->isOptionDisabled($slug, $label))->toBeTrue("{$slug} should be disabled");
    }
    expect($tiles->isOptionDisabled('discord', 'Discord'))->toBeFalse();

    foreach (chanBatchWizardCases() as [$slug, $data]) {
        chanChatFormSubmit($slug, $data)->assertNotified('Your plan can\'t add that channel');
    }

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('none of the seven is creatable in the app, the API or the alert form\'s inline create', function () {
    $this->seed(ProductCatalogSeeder::class);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Location::factory()->for($user)->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;
    Http::fake();

    $pickerSlugs = app(ChannelTypeRegistry::class)->forPicker()->map(fn (array $row) => $row['type']->slug())->all();
    expect($pickerSlugs)->toContain(...CHAN_BATCH_SLUGS);

    $creatable = app(ChannelTypeRegistry::class)->creatableInApp($user)->map->slug()->all();
    $listed = collect($this->withToken($token)->getJson('/api/v1/channel-types')->assertOk()->json('data'))->pluck('slug')->all();

    expect($creatable)->toContain('discord')
        ->and(array_intersect($creatable, CHAN_BATCH_SLUGS))->toBe([])
        ->and($listed)->toContain('discord')
        ->and(array_intersect($listed, CHAN_BATCH_SLUGS))->toBe([]);

    foreach (chanBatchWizardCases() as [$slug, $data]) {
        app('auth')->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/channels', ['channel_type' => $slug, ...$data])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel_type']);
    }
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();

    $form = livewire(CreateAlertRule::class)->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionExists('channel_ids', 'connect_discord');
    foreach (CHAN_BATCH_SLUGS as $slug) {
        $form->assertFormComponentActionDoesNotExist('channel_ids', "connect_{$slug}");
    }
});

test('a downgraded subscriber\'s seven channels are kept, refused with the tier text and resume on re-upgrade', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channels = chanBatchChannelsFor($user);
    $rule = AlertRule::factory()->for($user)->create();
    $rule->notificationChannels()->attach(array_map(fn (NotificationChannel $channel): int => $channel->id, $channels));
    $send = fn (NotificationChannel $channel, string $type): mixed => app(ChannelDispatcher::class)
        ->send($channel->fresh(), 'Tornado Warning', 'Alert', ['type' => $type, 'code' => '123456']);

    foreach ($channels as $channel) {
        expect($send($channel, 'alert')->success)->toBeTrue("{$channel->channel_type} should send on Plus");
    }
    Http::assertSentCount(7);

    $user->forceFill(['tier_override' => null])->save();

    foreach ($channels as $channel) {
        foreach (['alert', 'test', 'confirmation'] as $type) {
            expect($send($channel, $type))->success->toBeFalse()->error->toBe(ChannelDispatcher::TIER_REFUSAL);
        }
        expect($channel->fresh())->not->toBeNull()
            ->and($user->fresh()->canAddChannel($channel->channel_type))->toBeFalse();
    }
    Http::assertSentCount(7);
    expect($rule->notificationChannels()->count())->toBe(7);

    $user->forceFill(['tier_override' => Tier::Pro])->save();

    foreach ($channels as $channel) {
        expect($send($channel, 'alert')->success)->toBeTrue("{$channel->channel_type} should resume");
    }
    Http::assertSentCount(14);
});

test('the app\'s channel list shows them generically and never carries a secret', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    chanBatchChannelsFor($user);
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/channels')->assertOk();
    $rows = collect($response->json('data'))->keyBy('channel_type');

    expect($rows->keys()->all())->toEqualCanonicalizing(CHAN_BATCH_SLUGS)
        ->and($rows->only(['google_chat', 'mattermost', 'rocketchat', 'matrix', 'whatsapp', 'pagerduty', 'bluesky'])->map->type_label->all())->toBe([
            'google_chat' => 'Google Chat', 'mattermost' => 'Mattermost', 'rocketchat' => 'Rocket.Chat', 'matrix' => 'Matrix', 'whatsapp' => 'WhatsApp', 'pagerduty' => 'PagerDuty', 'bluesky' => 'Bluesky',
        ])
        ->and($rows['matrix']['destination'])->toBe('!AbCdEfGhIjKlMn:example.org')
        ->and($rows['matrix']['destination_label'])->toBe('Matrix room')
        ->and($rows['whatsapp']['destination'])->toBe('+52 •••• 5678');

    foreach ($rows as $row) {
        expect(array_keys($row))->toBe(['id', 'label', 'channel_type', 'type_label', 'destination', 'destination_label', 'verified', 'managed_by_app', 'created_at']);
    }

    $body = $response->getContent();
    foreach ([
        'AIzaSyDdI0hCZtE6vySjMm', 'Zq3x-_Vb9Kd2LmNo', 'h7kq3m9xw5e8rbt4y6uzi3o5pa', 'Zx9Cv8Bn7Mq6Wr5Ty4Ui3Op2As1Df0Gh',
        'syt_d3hub3RpZnk', 'EAABsbCS1iHgBO7ZBx9K', 'e93facc04764012d7bfb002500d5d1a6', 'k7m2-q4xz-p9hw-3tab', '525512345678', '5512345678', 'apprise_url', '"config"', '"token"',
    ] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

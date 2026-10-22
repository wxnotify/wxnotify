<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\GoogleChatChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\Transports\MessagingTransport;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

const CHAN_GCHAT_SPACE = 'AAAAbCdEf12';
const CHAN_GCHAT_KEY = 'AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI';
const CHAN_GCHAT_TOKEN = 'Zq3x-_Vb9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJkLmN=';
const CHAN_GCHAT_ENCODED_TOKEN = 'Zq3x-_Vb9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJkLmN%3D';
const CHAN_GCHAT_MESSAGE = 'Paste the whole webhook URL from Google Chat: Apps & integrations → Webhooks.';

beforeEach(function () {
    $this->type = new GoogleChatChannelType;
});

/**
 * A webhook URL as Google Chat shows it under Apps & integrations → Webhooks.
 */
function chanGoogleChatWebhookUrl(string $query = 'key='.CHAN_GCHAT_KEY.'&token='.CHAN_GCHAT_ENCODED_TOKEN): string
{
    return 'https://chat.googleapis.com/v1/spaces/'.CHAN_GCHAT_SPACE.'/messages?'.$query;
}

/**
 * Whether the webhook field's own rules accept the value, and its errors.
 *
 * @return array{passes: bool, errors: list<string>}
 */
function chanGoogleChatValidate(GoogleChatChannelType $type, mixed $value): array
{
    $field = collect($type->formSchema())->keyBy(fn ($component) => $component->getName())['webhook_url'];
    $validator = Validator::make(['webhook_url' => $value], ['webhook_url' => $field->getValidationRules()]);

    return ['passes' => $validator->passes(), 'errors' => $validator->errors()->get('webhook_url')];
}

test('it parses a pasted webhook url into space, key and token', function () {
    $destination = $this->type->buildDestination(['webhook_url' => '  '.chanGoogleChatWebhookUrl().'&threadKey=storm-1  ']);

    expect($destination)->toBe([
        'config' => ['space' => CHAN_GCHAT_SPACE, 'key' => CHAN_GCHAT_KEY, 'token' => CHAN_GCHAT_TOKEN],
        'label' => 'Google Chat',
    ])
        ->and(chanGoogleChatValidate($this->type, chanGoogleChatWebhookUrl())['passes'])->toBeTrue()
        ->and($this->type->appriseUrl($destination['config']))
        ->toBe('gchat://'.CHAN_GCHAT_SPACE.'/'.CHAN_GCHAT_KEY.'/'.CHAN_GCHAT_ENCODED_TOKEN)
        ->and($this->type->serverUrl($destination['config']))->toBeNull()
        ->and($this->type->destinationKey($destination['config']))->toBe(CHAN_GCHAT_SPACE)
        ->and($this->type->requiredConfigKeys())->toBe(['space', 'key', 'token'])
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->slug())->toBe('google_chat')
        ->and($this->type->icon())->toBe('fab-google')
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse();
});

test('the token is percent-decoded once, never twice', function () {
    $config = $this->type->buildDestination(['webhook_url' => chanGoogleChatWebhookUrl('token=ab%3D%3D&key=k1')])['config'];

    expect($config['token'])->toBe('ab==')
        ->and($config['key'])->toBe('k1')
        ->and(chanGoogleChatValidate($this->type, chanGoogleChatWebhookUrl('key=k1&token=ab%253D'))['passes'])->toBeFalse();
});

test('it refuses another host, http, a missing key or token, and a partial url', function (string $url) {
    expect(chanGoogleChatValidate($this->type, $url))->toBe(['passes' => false, 'errors' => [CHAN_GCHAT_MESSAGE]])
        ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))
        ->toThrow(InvalidArgumentException::class, CHAN_GCHAT_MESSAGE);
})->with([
    'another host' => fn () => str_replace('chat.googleapis.com', 'chat.googleapis.com.evil.example', chanGoogleChatWebhookUrl()),
    'a lookalike host' => fn () => str_replace('chat.googleapis.com', 'chat-googleapis.com', chanGoogleChatWebhookUrl()),
    'http' => fn () => str_replace('https://', 'http://', chanGoogleChatWebhookUrl()),
    'userinfo' => fn () => str_replace('https://', 'https://user:pw@', chanGoogleChatWebhookUrl()),
    'a port' => fn () => str_replace('googleapis.com/', 'googleapis.com:8443/', chanGoogleChatWebhookUrl()),
    'no key' => fn () => chanGoogleChatWebhookUrl('token='.CHAN_GCHAT_ENCODED_TOKEN),
    'no token' => fn () => chanGoogleChatWebhookUrl('key='.CHAN_GCHAT_KEY),
    'a blank token' => fn () => chanGoogleChatWebhookUrl('key='.CHAN_GCHAT_KEY.'&token='),
    'a slash in the token' => fn () => chanGoogleChatWebhookUrl('key='.CHAN_GCHAT_KEY.'&token=ab%2Fcd'),
    'only the key' => fn () => CHAN_GCHAT_KEY,
    'no query' => fn () => 'https://chat.googleapis.com/v1/spaces/'.CHAN_GCHAT_SPACE.'/messages',
    'no space' => fn () => 'https://chat.googleapis.com/v1/spaces//messages?key=k&token=t',
    'another path' => fn () => 'https://chat.googleapis.com/v1/spaces/'.CHAN_GCHAT_SPACE.'/members?key=k&token=t',
    'a space with a slash' => fn () => 'https://chat.googleapis.com/v1/spaces/AAAA%2F..%2Fx/messages?key=k&token=t',
]);

test('it percent-encodes every component in the apprise url', function (string $component, string $encoded) {
    $config = ['space' => CHAN_GCHAT_SPACE, 'key' => CHAN_GCHAT_KEY, 'token' => CHAN_GCHAT_TOKEN, $component => 'a/b@c:d?e#f%g h é'];
    $expected = [
        'space' => CHAN_GCHAT_SPACE,
        'key' => CHAN_GCHAT_KEY,
        'token' => CHAN_GCHAT_ENCODED_TOKEN,
        $component => $encoded,
    ];

    expect($this->type->appriseUrl($config))->toBe("gchat://{$expected['space']}/{$expected['key']}/{$expected['token']}");
})->with([
    'space' => ['space', 'a%2Fb%40c%3Ad%3Fe%23f%25g%20h%20%C3%A9'],
    'key' => ['key', 'a%2Fb%40c%3Ad%3Fe%23f%25g%20h%20%C3%A9'],
    'token' => ['token', 'a%2Fb%40c%3Ad%3Fe%23f%25g%20h%20%C3%A9'],
]);

test('the shared guarded query turns redirects off and sets the timeouts, with extras appended', function () {
    expect($this->type->guardedQuery())->toBe('redirect=no&cto=5&rto=10')
        ->and($this->type->guardedQuery(['discovery' => 'no', 'e2ee' => 'no']))->toBe('redirect=no&cto=5&rto=10&discovery=no&e2ee=no');
});

test('completing the wizard as Plus stores config only and posts the code', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'google_chat']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(['webhook_url' => chanGoogleChatWebhookUrl()]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3)
        ->assertSee('We just posted a 6-digit code to your Google Chat space.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('google_chat')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Google Chat')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['space' => CHAN_GCHAT_SPACE, 'key' => CHAN_GCHAT_KEY, 'token' => CHAN_GCHAT_TOKEN]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'gchat://'.CHAN_GCHAT_SPACE.'/'.CHAN_GCHAT_KEY.'/'.CHAN_GCHAT_ENCODED_TOKEN
        && ! array_key_exists('title', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}"));
});

test('the wizard shows the field\'s own error for a bad url and creates nothing', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'google_chat']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['webhook_url' => 'https://example.com/v1/spaces/AAAA/messages?key=k&token=t']);
    goToConnectChannelStep($component, 1);

    $component->assertHasFormErrors(['webhook_url']);
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a Free subscriber sees the tile disabled and is refused on a forced nextStep', function () {
    $free = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($free);
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->toHaveKey('google_chat')
        ->and($tile->isOptionDisabled('google_chat', 'Google Chat'))->toBeTrue();

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'google_chat'])
        ->fillForm(['webhook_url' => chanGoogleChatWebhookUrl()]);
    goToConnectChannelStep($component, 1);

    $component->assertNotified('Your plan can\'t add that channel');
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a 424 records the fixed Google Chat text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->googleChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Google Chat did not accept the message. Check the webhook URL and that the webhook still exists in the space.')
        ->and($this->type->failureMessage())->toBe($result->error);
});

test('the wizard says the code could not be posted when Google Chat refuses it', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'google_chat']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['webhook_url' => chanGoogleChatWebhookUrl()]);
    goToConnectChannelStep($component, 1);

    $component->assertNotified(Notification::make()
        ->title('Couldn\'t send your confirmation code')
        ->body('We couldn\'t post to that Google Chat webhook. Check the URL, and that the webhook still exists in the space.')
        ->danger());
    expect(NotificationChannel::count())->toBe(0);
});

test('an Apprise connection error propagates from the transport', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->googleChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'Tornado Warning'))
        ->toThrow(ConnectionException::class);
});

test('the transport refuses a channel missing its settings or of a non-messaging type without calling Apprise', function () {
    Http::fake();

    $blank = NotificationChannel::factory()->googleChat()->create();
    $blank->config = [...$blank->config, 'token' => ''];

    $absent = NotificationChannel::factory()->googleChat()->create();
    $absent->config = collect($absent->config)->except('space')->all();

    $notMessaging = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    expect(app(MessagingTransport::class)->send($blank, 'body', null)->error)->toBe('This channel is missing its settings.')
        ->and(app(MessagingTransport::class)->send($absent, 'body', null)->error)->toBe('This channel is missing its settings.')
        ->and(app(MessagingTransport::class)->send($notMessaging, 'body', null)->error)->toBe('This channel cannot send messages.');

    Http::assertNothingSent();
});

test('a messaging type that names its own server is refused before any HTTP when the server check refuses it', function () {
    Http::fake();
    $ownServer = new class extends GoogleChatChannelType
    {
        public function slug(): string
        {
            return 'chan_own_server_chat';
        }

        public function serverUrl(array $config): ?string
        {
            return 'https://chat.example.com';
        }
    };
    app()->instance(ChannelTypeRegistry::class, new class($ownServer) extends ChannelTypeRegistry
    {
        public function __construct(private readonly ChannelType $ownServer) {}

        public function find(string $slug): ?ChannelType
        {
            return $slug === 'chan_own_server_chat' ? $this->ownServer : parent::find($slug);
        }
    });
    $channel = NotificationChannel::factory()->googleChat()->create(['channel_type' => 'chan_own_server_chat']);

    chanFakeChatDns(['chat.example.com' => ['10.0.0.5']]);

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->error)->toBe(ChatServerGuard::PRIVATE_NETWORK);
    Http::assertNothingSent();
});

test('an alert is posted as the composed chat text with no title', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->googleChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, "Tornado Warning for\nBoulder County.", 'Tornado Warning', ['url' => 'https://wx.example.com/view-product/kbou-1']);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => ! array_key_exists('title', $request->data())
        && $request['body'] === "Tornado Warning\n\nTornado Warning for\nBoulder County.\n\nhttps://wx.example.com/view-product/kbou-1");
});

test('the secret never appears in config, the activity log, the failure detail or the edit modal', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, ['webhook_url' => chanGoogleChatWebhookUrl()]);
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'Ops room']);

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');
    $log = json_encode(Activity::all()->toArray());

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $snapshot = json_encode($component->snapshot);

    foreach ([CHAN_GCHAT_TOKEN, CHAN_GCHAT_ENCODED_TOKEN, CHAN_GCHAT_KEY] as $secret) {
        expect($raw)->not->toContain($secret)
            ->and($log)->not->toContain($secret)
            ->and(json_encode($failure->toArray()))->not->toContain($secret)
            ->and($snapshot)->not->toContain($secret);
    }

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the summary masks the space and the caption is fixed', function () {
    $channel = NotificationChannel::factory()->googleChat()->create();

    expect($this->type->displaySummary($channel))->toBe('spaces/••••Ef12')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'google_chat', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('Google Chat space')
        ->and($this->type->confirmationHint($channel))->toBe('We just posted a 6-digit code to your Google Chat space.')
        ->and($this->type->codeNotSentHint())->toBe('We couldn\'t post to that Google Chat webhook. Check the URL, and that the webhook still exists in the space.');
});

test('the API refuses to create a google_chat channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'google_chat', 'webhook_url' => chanGoogleChatWebhookUrl()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

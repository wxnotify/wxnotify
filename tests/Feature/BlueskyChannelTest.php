<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\SendTestNotification;
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
use App\Support\BlueskyConsent;
use App\Support\ChannelTypes\BlueskyChannelType;
use App\Support\Delivery\BlueskyAccountGuard;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\MessagingTransport;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

const CHAN_BSKY_HANDLE = 'wxdesk.bsky.social';

const CHAN_BSKY_PASSWORD = 'k7m2-q4xz-p9hw-3tab';

const CHAN_BSKY_DID = 'did:plc:abcdefghijklmnopqrstuvwx';

const CHAN_BSKY_PDS = 'https://puffball.us-east.host.bsky.network';

const CHAN_BSKY_URL_SPECIALS = ['/', '@', ':', '?', '#', '%', ' ', '&', '=', '+', ';', ',', '~', '!', '$', "'", '(', '*', '[', '\\', '_', 'é', "\n", "\t", "\0"];

beforeEach(function () {
    $this->type = new BlueskyChannelType;
});

/**
 * The exact Apprise URL a Bluesky channel sends with.
 */
function chanBlueskyUrl(string $handle = CHAN_BSKY_HANDLE, string $password = CHAN_BSKY_PASSWORD): string
{
    return "bsky://{$handle}@{$password}?redirect=no&cto=5&rto=10";
}

/**
 * A plc.directory DID document for the account.
 *
 * @return array<string, mixed>
 */
function chanBlueskyDocument(mixed $endpoint = CHAN_BSKY_PDS, string $handle = CHAN_BSKY_HANDLE, string $did = CHAN_BSKY_DID): array
{
    return [
        '@context' => ['https://www.w3.org/ns/did/v1'],
        'id' => $did,
        'alsoKnownAs' => ["at://{$handle}"],
        'verificationMethod' => [['id' => "{$did}#atproto", 'type' => 'Multikey', 'controller' => $did, 'publicKeyMultibase' => 'zQ3sh']],
        'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $endpoint]],
    ];
}

/**
 * Fakes the handle lookup, the DID document and Apprise.
 *
 * @param  array<string, mixed>|null  $document
 */
function chanBlueskyFake(?array $document = null, string $did = CHAN_BSKY_DID, int $notifyStatus = 200): void
{
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => $did]),
        'plc.directory/*' => Http::response($document ?? chanBlueskyDocument()),
        '*/notify/*' => Http::response(status: $notifyStatus),
    ]);
}

/**
 * The step 2 form data.
 *
 * @return array<string, mixed>
 */
function chanBlueskyForm(array $overrides = []): array
{
    return ['handle' => CHAN_BSKY_HANDLE, 'app_password' => CHAN_BSKY_PASSWORD, 'consent' => true, ...$overrides];
}

/**
 * Every URL-special character, raw and percent-encoded, spliced into a value.
 *
 * @return array<string, string>
 */
function chanBlueskySpecials(string $where, Closure $splice): array
{
    $cases = [];

    foreach (CHAN_BSKY_URL_SPECIALS as $character) {
        $cases["{$where}: raw ".json_encode($character)] = $splice($character);
        $cases["{$where}: encoded ".rawurlencode($character)] = $splice(rawurlencode($character));
    }

    return $cases;
}

/**
 * The requests that went to the given host.
 */
function chanBlueskyRequestsTo(string $host): int
{
    return collect(Http::recorded())->filter(fn (array $pair): bool => parse_url($pair[0]->url(), PHP_URL_HOST) === $host)->count();
}

function chanBlueskyNotifyCount(): int
{
    return collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/notify/'))->count();
}

function chanBlueskyPlusUser(): User
{
    return User::factory()->create(['tier_override' => Tier::Plus]);
}

test('it builds the config from the form after verifying the account, and the type has the messaging flags', function () {
    $this->freezeTime();
    chanBlueskyFake();

    $destination = $this->type->buildDestination(chanBlueskyForm(['handle' => ' @WxDesk.Bsky.Social ', 'app_password' => ' '.CHAN_BSKY_PASSWORD.' ']));

    expect($destination)->toBe([
        'config' => [
            'handle' => CHAN_BSKY_HANDLE,
            'app_password' => CHAN_BSKY_PASSWORD,
            'did' => CHAN_BSKY_DID,
            'consent_version' => BlueskyConsent::VERSION,
            'consented_at' => now()->toIso8601String(),
        ],
        'label' => 'Bluesky',
    ])
        ->and($this->type->appriseUrl($destination['config']))->toBe(chanBlueskyUrl())
        ->and($this->type->serverUrl($destination['config']))->toBeNull()
        ->and($this->type->requiredConfigKeys())->toBe(['handle', 'app_password', 'did'])
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->slug())->toBe('bluesky')
        ->and($this->type->label())->toBe('Bluesky')
        ->and($this->type->icon())->toBe('fab-bluesky')
        ->and($this->type->maxLength())->toBe(280)
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->isAvailable())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=wxdesk.bsky.social');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://plc.directory/'.CHAN_BSKY_DID);
    Http::assertSentCount(2);
});

test('the handle must be a plain domain-style handle', function (string $handle) {
    Http::fake();
    $message = BlueskyChannelType::INVALID_HANDLE_MESSAGE;

    expect(chanChatFieldValidate($this->type, 'handle', $handle))->passes->toBeFalse()->errors->toContain($message)
        ->and(fn () => $this->type->buildDestination(chanBlueskyForm(['handle' => $handle])))
        ->toThrow(InvalidArgumentException::class, $message);
    Http::assertNothingSent();
})->with([
    'one label' => 'wxdesk',
    'an empty label' => 'wx..bsky.social',
    'a leading dot' => '.wxdesk.bsky.social',
    'a trailing dot' => 'wxdesk.bsky.social.',
    'a label starting with a dash' => '-wx.bsky.social',
    'a label ending with a dash' => 'wx-.bsky.social',
    'a digit-first top label' => 'wxdesk.bsky.4social',
    'a label of 64 characters' => str_repeat('a', 64).'.bsky.social',
    'longer than 253 characters' => implode('.', array_fill(0, 5, str_repeat('a', 50))).'.social',
    'a url' => 'https://bsky.app/profile/wxdesk.bsky.social',
    'an email' => 'me@wxdesk.bsky.social',
    'two at signs' => '@@wxdesk.bsky.social',
    'a newline after it' => CHAN_BSKY_HANDLE."\nx",
    ...chanBlueskySpecials('inside', fn (string $character): string => 'wx'.$character.'desk.bsky.social'),
    ...array_filter(chanBlueskySpecials('last', fn (string $character): string => CHAN_BSKY_HANDLE.$character), fn (string $handle): bool => trim($handle) === $handle),
]);

test('the app password must be a Bluesky App Password', function (string $password) {
    Http::fake();
    $message = BlueskyChannelType::INVALID_PASSWORD_MESSAGE;

    expect(chanChatFieldValidate($this->type, 'app_password', $password))->passes->toBeFalse()->errors->toContain($message)
        ->and(fn () => $this->type->buildDestination(chanBlueskyForm(['app_password' => $password])))
        ->toThrow(InvalidArgumentException::class, $message);
    Http::assertNothingSent();
})->with([
    'an account password' => 'hunter2-Correct-Horse',
    'no dashes' => 'k7m2q4xzp9hw3tab',
    'upper case' => strtoupper(CHAN_BSKY_PASSWORD),
    'a short group' => 'k7m-q4xz-p9hw-3tab',
    'five groups' => CHAN_BSKY_PASSWORD.'-abcd',
    'a newline after it' => CHAN_BSKY_PASSWORD."\nx",
    ...chanBlueskySpecials('inside', fn (string $character): string => 'k7m2-q4'.$character.'z-p9hw-3tab'),
    ...chanBlueskySpecials('last', fn (string $character): string => 'k7m2-q4xz-p9hw-3ta'.$character),
]);

test('a well-formed handle and app password pass their own rules', function () {
    expect(chanChatFieldValidate($this->type, 'handle', '@Storm-Desk.example.co.uk')['passes'])->toBeTrue()
        ->and(chanChatFieldValidate($this->type, 'handle', 'a.b2')['passes'])->toBeTrue()
        ->and(chanChatFieldValidate($this->type, 'app_password', CHAN_BSKY_PASSWORD)['passes'])->toBeTrue()
        ->and(chanChatFieldValidate($this->type, 'app_password', 'abcd-0189-wxyz-2345')['passes'])->toBeTrue();
});

test('the public-posting consent is required and nothing is looked up or sent without it', function () {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake();

    chanChatFormSubmit('bluesky', chanBlueskyForm(['consent' => false]))
        ->assertHasFormErrors(['consent']);

    expect(fn () => $this->type->buildDestination(chanBlueskyForm(['consent' => false])))
        ->toThrow(InvalidArgumentException::class, BlueskyChannelType::CONSENT_REQUIRED_MESSAGE)
        ->and(NotificationChannel::count())->toBe(0)
        ->and(BlueskyConsent::TEXT)->toBe('Every alert, test and confirmation code is posted publicly on this Bluesky account and is visible to anyone.');
    Http::assertNothingSent();
});

test('an account whose DID is not a did:plc is refused as not hosted by Bluesky, without fetching any document', function (string $did) {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake(did: $did);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([
    'did:web' => 'did:web:wxdesk.example.com',
    'did:key' => 'did:key:zQ3shokFTS3brHcDQrn82RUDfCZESWL1ZdCEJwekUDPQiYBme',
    'a plc DID with a path' => 'did:plc:abcdefghijklmnopqrstuvwx/../x',
    'a plc DID too short' => 'did:plc:abcdefgh',
    'a plc DID in upper case' => 'did:plc:ABCDEFGHIJKLMNOPQRSTUVWX',
    'a plc DID with a 1' => 'did:plc:abcdefghijklmnopqrstuvw1',
    'a plc DID with a newline' => "did:plc:abcdefghijklmnopqrstuvwx\n",
]);

test('a DID document naming a PDS outside Bluesky\'s hosts is refused and nothing reaches Apprise', function (mixed $endpoint) {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake(chanBlueskyDocument($endpoint));

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([
    'another host' => 'https://evil.example',
    'http' => 'http://bsky.social',
    'http on a Bluesky host' => 'http://puffball.us-east.host.bsky.network',
    'a metadata address' => 'https://169.254.169.254',
    'a loopback address' => 'https://127.0.0.1',
    'bsky.social as a prefix' => 'https://bsky.social.evil.example',
    'bsky.network without .host' => 'https://evilhost.bsky.network',
    'host.bsky.network as a prefix' => 'https://x.host.bsky.network.evil.example',
    'the bare suffix' => 'https://host.bsky.network',
    'the suffix with a leading dot only' => 'https://.host.bsky.network',
    'an empty label' => 'https://x..host.bsky.network',
    'a sub-domain of bsky.social' => 'https://evil.bsky.social',
    'a port' => 'https://puffball.us-east.host.bsky.network:8443',
    'port 443 spelled out' => 'https://bsky.social:443',
    'userinfo' => 'https://evil.example@bsky.social',
    'a path' => 'https://bsky.social/evil',
    'a query' => 'https://bsky.social?x=1',
    'a fragment' => 'https://bsky.social#x',
    'upper case' => 'https://BSKY.SOCIAL',
    'a trailing dot' => 'https://bsky.social.',
    'a trailing slash' => 'https://morel.us-east.host.bsky.network/',
    'an encoded dot' => 'https://evil%2Eexample',
    'whitespace' => 'https://bsky.social ',
    'a fullwidth dot' => 'https://puffball。us-east.host.bsky.network',
    'not a string' => [['https://bsky.social']],
    'no endpoint' => null,
]);

test('every PDS service in the document must be Bluesky\'s, and one must exist', function (array $services) {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake([...chanBlueskyDocument(), 'service' => $services]);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([
    'a second PDS elsewhere' => [[
        ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => CHAN_BSKY_PDS],
        ['id' => '#other', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://evil.example'],
    ]],
    'an evil PDS first' => [[
        ['id' => '#evil', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://evil.example'],
        ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => CHAN_BSKY_PDS],
    ]],
    'no services' => [[]],
    'only a labeler' => [[['id' => '#atproto_labeler', 'type' => 'AtprotoLabeler', 'serviceEndpoint' => CHAN_BSKY_PDS]]],
    'a Bluesky PDS under the wrong id' => [[['id' => '#other_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => CHAN_BSKY_PDS]]],
]);

test('Bluesky\'s own PDS hosts are accepted', function (string $endpoint, string $serviceId) {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake([...chanBlueskyDocument(), 'service' => [['id' => $serviceId, 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $endpoint]]]);

    chanChatFormSubmit('bluesky', chanBlueskyForm())->assertHasNoFormErrors();

    expect(NotificationChannel::sole()->config['did'])->toBe(CHAN_BSKY_DID)
        ->and(chanBlueskyNotifyCount())->toBe(1);
})->with([
    'bsky.social' => ['https://bsky.social', '#atproto_pds'],
    'a us-east mushroom' => [CHAN_BSKY_PDS, '#atproto_pds'],
    'a us-west mushroom' => ['https://woodtuft.us-west.host.bsky.network', '#atproto_pds'],
    'the full service id' => [CHAN_BSKY_PDS, CHAN_BSKY_DID.'#atproto_pds'],
]);

test('the allowed PDS hosts are one fixed list', function () {
    expect(BlueskyAccountGuard::ALLOWED_PDS_HOSTS)->toBe(['bsky.social'])
        ->and(BlueskyAccountGuard::ALLOWED_PDS_HOST_SUFFIXES)->toBe(['.host.bsky.network']);
});

test('a handle the DID document does not claim back is refused', function (array $document) {
    $this->actingAs(chanBlueskyPlusUser());
    chanBlueskyFake($document);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::HANDLE_NOT_FOUND);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([
    'another handle' => [chanBlueskyDocument(handle: 'someone.bsky.social')],
    'a handle with this one as a suffix' => [chanBlueskyDocument(handle: 'x.'.CHAN_BSKY_HANDLE)],
    'no alsoKnownAs' => [[...chanBlueskyDocument(), 'alsoKnownAs' => []]],
    'an https alias, not at://' => [[...chanBlueskyDocument(), 'alsoKnownAs' => ['https://'.CHAN_BSKY_HANDLE]]],
    'another DID\'s document' => [chanBlueskyDocument(did: 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz')],
]);

test('a handle Bluesky cannot resolve says so', function () {
    $this->actingAs(chanBlueskyPlusUser());
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400),
        '*' => Http::response(status: 200),
    ]);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::HANDLE_NOT_FOUND);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
});

test('a DID document plc.directory no longer has (deactivated or tombstoned) is not found, not try-again', function (int $status) {
    $this->actingAs(chanBlueskyPlusUser());
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]),
        'plc.directory/*' => Http::response(['message' => 'DID not available'], $status),
        '*/notify/*' => Http::response(status: 200),
    ]);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::HANDLE_NOT_FOUND);

    expect(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([404, 410]);

test('a lookup that fails gives its own text, distinct from the policy refusal, and sends nothing', function (array $stubs) {
    $this->actingAs(chanBlueskyPlusUser());
    Http::fake([...$stubs, '*/notify/*' => Http::response(status: 200)]);

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(BlueskyAccountGuard::UNVERIFIED);

    expect(BlueskyAccountGuard::UNVERIFIED)->not->toBe(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY)
        ->and(NotificationChannel::count())->toBe(0)
        ->and(chanBlueskyNotifyCount())->toBe(0);
})->with([
    'resolve 500' => [fn (): array => ['public.api.bsky.app/*' => Http::response(status: 500)]],
    'resolve 429' => [fn (): array => ['public.api.bsky.app/*' => Http::response(status: 429)]],
    'resolve redirects' => [fn (): array => ['public.api.bsky.app/*' => Http::response(status: 302, headers: ['Location' => 'https://evil.example/'])]],
    'resolve unreachable' => [fn (): array => ['public.api.bsky.app/*' => Http::failedConnection()]],
    'resolve not JSON' => [fn (): array => ['public.api.bsky.app/*' => Http::response('<html>')]],
    'resolve without a did' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['handle' => CHAN_BSKY_HANDLE])]],
    'resolve with a non-string did' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => ['did:plc:x']])]],
    'resolve too big' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID, 'pad' => str_repeat('a', 70000)])]],
    'document 500' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::response(status: 500)]],
    'document redirects' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::response(status: 301, headers: ['Location' => 'https://evil.example/'])]],
    'document unreachable' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::failedConnection()]],
    'document not JSON' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::response('{"id": ')]],
    'document a list' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::response('["x"]')]],
    'document too big' => [fn (): array => ['public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]), 'plc.directory/*' => Http::response([...chanBlueskyDocument(), 'pad' => str_repeat('a', 70000)])]],
]);

test('the lookups go to the fixed hosts without redirects, with short timeouts and nothing secret', function () {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [$request->method(), $request->url(), $options['allow_redirects'] ?? null, $options['connect_timeout'] ?? null, $options['timeout'] ?? null, $request->hasHeader('Authorization')];

        return parse_url($request->url(), PHP_URL_HOST) === 'public.api.bsky.app'
            ? Http::response(['did' => CHAN_BSKY_DID])
            : Http::response(chanBlueskyDocument());
    });

    expect(app(BlueskyAccountGuard::class)->verifiedDid(CHAN_BSKY_HANDLE))->toBe(CHAN_BSKY_DID)
        ->and($seen)->toBe([
            ['GET', 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=wxdesk.bsky.social', false, 5, 10, false],
            ['GET', 'https://plc.directory/'.CHAN_BSKY_DID, false, 5, 10, false],
        ]);
});

test('completing the wizard as Plus verifies the account, stores config only, records the consent and posts the code', function () {
    $user = chanBlueskyPlusUser();
    $this->actingAs($user);
    chanBlueskyFake();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'bluesky']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertSee('Only accounts hosted by Bluesky are supported')
        ->assertSee('never your main account password')
        ->assertSee(BlueskyConsent::TEXT)
        ->fillForm(chanBlueskyForm());
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3)
        ->assertSee('We just posted a 6-digit code publicly on your Bluesky account. Read it there, then delete that post.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('bluesky')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Bluesky')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toMatchArray(['handle' => CHAN_BSKY_HANDLE, 'app_password' => CHAN_BSKY_PASSWORD, 'did' => CHAN_BSKY_DID, 'consent_version' => BlueskyConsent::VERSION])
        ->and($channel->config['consented_at'])->toBeString();

    $consent = Activity::query()->where('event', 'bluesky_consent')->sole();
    expect($consent->subject_id)->toBe($channel->id)
        ->and($consent->properties->all())->toBe(['channel_type' => 'bluesky', 'version' => BlueskyConsent::VERSION])
        ->and(str_contains(json_encode($consent->toArray()), 'wxdesk'))->toBeFalse()
        ->and(str_contains(json_encode($consent->toArray()), CHAN_BSKY_PASSWORD))->toBeFalse();

    expect(chanBlueskyNotifyCount())->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/notify/')
        && $request['urls'] === chanBlueskyUrl()
        && ! array_key_exists('title', $request->data())
        && ! array_key_exists('format', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}")
        && mb_strlen($request['body']) <= 280);

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => $channel->confirmation_code])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('an alert, the code and a test go out on the exact URL after the account is checked', function () {
    chanBlueskyFake();
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();
    $dispatcher = app(ChannelDispatcher::class);

    expect($dispatcher->send($channel, 'Tornado Warning', 'Alert')->success)->toBeTrue()
        ->and($dispatcher->send($channel, 'Your WxNotify confirmation code is: 123456', 'Confirm', ['type' => 'confirmation', 'code' => '123456'])->success)->toBeTrue()
        ->and(app(SendTestNotification::class)($channel))->toBeTrue();

    $notified = collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/notify/'));

    expect($notified->map(fn (array $pair): string => $pair[0]['urls'])->values()->all())->toBe([chanBlueskyUrl(), chanBlueskyUrl(), chanBlueskyUrl()])
        ->and($notified->every(fn (array $pair): bool => ! array_key_exists('format', $pair[0]->data()) && ! array_key_exists('title', $pair[0]->data())))->toBeTrue();
});

test('every send re-checks the account: a document now naming another host refuses the send before Apprise', function () {
    $user = chanBlueskyPlusUser();
    $this->actingAs($user);
    $endpoint = CHAN_BSKY_PDS;
    Http::fake(function (Request $request) use (&$endpoint) {
        return match (parse_url($request->url(), PHP_URL_HOST)) {
            'public.api.bsky.app' => Http::response(['did' => CHAN_BSKY_DID]),
            'plc.directory' => Http::response(chanBlueskyDocument($endpoint)),
            default => Http::response(status: 200),
        };
    });

    chanChatFormSubmit('bluesky', chanBlueskyForm())->assertHasNoFormErrors();
    $channel = NotificationChannel::sole();
    $channel->forceFill(['verified_at' => now()])->save();
    expect(chanBlueskyNotifyCount())->toBe(1);

    $endpoint = 'https://evil.example';
    $this->travel(11)->minutes();

    expect(app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY)
        ->and(chanBlueskyNotifyCount())->toBe(1)
        ->and($channel->fresh())->not->toBeNull();
});

test('a send whose handle now belongs to another account is refused before Apprise', function () {
    chanBlueskyFake(chanBlueskyDocument(did: 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz'), did: 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz');
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(BlueskyChannelType::ACCOUNT_CHANGED_MESSAGE)
        ->and(chanBlueskyNotifyCount())->toBe(0);
});

test('a lookup failure at send time refuses with the try-again text and sends nothing', function () {
    Http::fake(['public.api.bsky.app/*' => Http::failedConnection(), '*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(BlueskyAccountGuard::UNVERIFIED)
        ->and(chanBlueskyNotifyCount())->toBe(0);
});

test('a successful check is cached for 10 minutes under a hash of the handle, and a failure is never cached', function () {
    $failing = true;
    Http::fake(function (Request $request) use (&$failing) {
        return match (parse_url($request->url(), PHP_URL_HOST)) {
            'public.api.bsky.app' => Http::response(['did' => CHAN_BSKY_DID]),
            'plc.directory' => $failing ? Http::response(status: 503) : Http::response(chanBlueskyDocument()),
            default => Http::response(status: 200),
        };
    });
    $channel = NotificationChannel::factory()->bluesky('WxDesk.bsky.social')->for(chanBlueskyPlusUser())->create();
    $send = fn (): bool => app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert')->success;
    $key = 'bluesky-account:'.hash('sha256', CHAN_BSKY_HANDLE);

    expect($send())->toBeFalse()
        ->and(Cache::has($key))->toBeFalse()
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(1);

    $failing = false;

    expect($send())->toBeTrue()
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(2)
        ->and(Cache::get($key))->toBe(CHAN_BSKY_DID)
        ->and(BlueskyAccountGuard::cacheKey(' WXDESK.bsky.social'))->toBe($key)
        ->and(str_contains($key, 'wxdesk'))->toBeFalse();

    expect($send())->toBeTrue()
        ->and(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(2)
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(2);

    $this->travel(9)->minutes();
    expect($send())->toBeTrue()
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(2);

    $this->travel(2)->minutes();
    expect($send())->toBeTrue()
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(3)
        ->and(chanBlueskyNotifyCount())->toBe(4);
});

test('a tampered stored handle or password gives no url and nothing is sent or looked up', function (string $key, mixed $value) {
    Http::fake();
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();
    $channel->config = [...$channel->config, $key => $value];

    expect($this->type->appriseUrl($channel->config))->toBe('')
        ->and(app(MessagingTransport::class)->send($channel, 'Tornado Warning', 'Alert')->error)->toBe('This channel is missing its settings.')
        ->and(app(MessagingTransport::class)->send($channel, 'code', null, ['type' => 'confirmation'])->error)->toBe('This channel is missing its settings.');

    if ($key === 'handle') {
        expect($this->type->sendRefusal($channel->config))->toBe('This channel is missing its settings.');
    }

    Http::assertNothingSent();
})->with([
    'an at sign in the handle' => ['handle', 'evil.example@wxdesk.bsky.social'],
    'an encoded at sign in the handle' => ['handle', 'evil.example%40wxdesk.bsky.social'],
    'a slash in the handle' => ['handle', 'wxdesk.bsky.social/x'],
    'an upper-case handle' => ['handle', 'WxDesk.bsky.social'],
    'a leading at in the handle' => ['handle', '@'.CHAN_BSKY_HANDLE],
    'a query in the handle' => ['handle', CHAN_BSKY_HANDLE.'?x=1'],
    'a one-label handle' => ['handle', 'wxdesk'],
    'a handle that is not a string' => ['handle', ['x']],
    'a slash in the password' => ['app_password', 'k7m2-q4xz-p9hw-3ta/'],
    'an encoded slash in the password' => ['app_password', 'k7m2-q4xz-p9hw-%2F'],
    'a query in the password' => ['app_password', CHAN_BSKY_PASSWORD.'?redirect=yes'],
    'an at sign in the password' => ['app_password', 'k7m2-q4xz@p9hw-3tab'],
    'a main password' => ['app_password', 'Hunter2!'],
]);

test('a tampered stored DID is refused before Apprise', function () {
    chanBlueskyFake();
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();
    $channel->config = [...$channel->config, 'did' => 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz'];

    expect(app(MessagingTransport::class)->send($channel, 'Tornado Warning', 'Alert')->error)->toBe(BlueskyChannelType::ACCOUNT_CHANGED_MESSAGE)
        ->and(chanBlueskyNotifyCount())->toBe(0);
});

test('a second channel for the same account is refused, whatever handle or spelling names it', function (string $handle) {
    $user = chanBlueskyPlusUser();
    $this->actingAs($user);
    Http::fake(function (Request $request) use ($handle) {
        return match (parse_url($request->url(), PHP_URL_HOST)) {
            'public.api.bsky.app' => Http::response(['did' => CHAN_BSKY_DID]),
            'plc.directory' => Http::response([...chanBlueskyDocument(), 'alsoKnownAs' => ['at://'.strtolower(ltrim(trim($handle), '@')), 'at://'.CHAN_BSKY_HANDLE]]),
            default => Http::response(status: 200),
        };
    });
    NotificationChannel::factory()->for($user)->bluesky()->create();

    expect(fn () => app(BeginChannelConnection::class)($user, $this->type, chanBlueskyForm(['handle' => $handle, 'app_password' => 'abcd-efgh-ijkl-mnop'])))
        ->toThrow(InvalidArgumentException::class, 'You already have a Bluesky channel for that account.')
        ->and(NotificationChannel::count())->toBe(1)
        ->and(chanBlueskyNotifyCount())->toBe(0)
        ->and($this->type->destinationKey(['handle' => 'x.example.com', 'did' => CHAN_BSKY_DID]))->toBe(CHAN_BSKY_DID);
})->with([
    'as stored' => CHAN_BSKY_HANDLE,
    'upper case with an at' => '@WXDESK.Bsky.Social',
    'padded' => "  wxdesk.bsky.social\t",
    'another handle of the same account' => 'storm.example.com',
]);

test('a Free subscriber sees the tile disabled and is refused on a forced step without any lookup', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->toHaveKey('bluesky')
        ->and($tile->isOptionDisabled('bluesky', 'Bluesky'))->toBeTrue();

    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified('Your plan can\'t add that channel');

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a downgraded or unconfirmed subscriber\'s channel is refused before any lookup', function () {
    Http::fake();
    $downgraded = NotificationChannel::factory()->for(User::factory()->create())->bluesky()->create();
    $unconfirmed = NotificationChannel::factory()->for(chanBlueskyPlusUser())->bluesky()->unverified()->create();

    expect(app(ChannelDispatcher::class)->send($downgraded, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and(app(ChannelDispatcher::class)->send($unconfirmed, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::UNCONFIRMED_REFUSAL)
        ->and($downgraded->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

test('a 424 records the fixed Bluesky text and the wizard says the code was not posted', function () {
    chanBlueskyFake(notifyStatus: 424);
    $user = chanBlueskyPlusUser();
    $channel = NotificationChannel::factory()->bluesky()->for($user)->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Bluesky did not accept the post. Check the handle and the app password (an App Password from Bluesky settings, not your account password), and that the account is hosted by Bluesky.')
        ->and($this->type->failureMessage())->toBe($result->error);

    $channel->delete();
    $this->actingAs($user);
    chanChatFormSubmit('bluesky', chanBlueskyForm())
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body('We couldn\'t post to that Bluesky account. Check the handle and the app password, and that the account is hosted by Bluesky.')
            ->danger());
    expect(NotificationChannel::count())->toBe(0);
});

test('an Apprise connection error propagates from the transport without the app password', function () {
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]),
        'plc.directory/*' => Http::response(chanBlueskyDocument()),
        '*/notify/*' => Http::failedConnection(),
    ]);
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create();

    try {
        app(ChannelDispatcher::class)->send($channel, 'Tornado Warning');
        $this->fail('Expected a ConnectionException.');
    } catch (ConnectionException $exception) {
        expect(str_contains($exception->getMessage(), CHAN_BSKY_PASSWORD))->toBeFalse()
            ->and(str_contains((string) $exception, CHAN_BSKY_PASSWORD))->toBeFalse();
    }
});

test('an alert is the chat text capped at 280 characters with the link whole, so Apprise never cuts or splits it', function () {
    chanBlueskyFake();
    $channel = NotificationChannel::factory()->bluesky()->for(chanBlueskyPlusUser())->create(['label' => str_repeat('Storm desk ', 22)]);
    $link = 'https://wx.example.com/view-product/kbou-a_b_c';

    app(ChannelDispatcher::class)->send($channel, "Tornado Warning for\nBoulder County. *bold* & <b> 🌪️", 'Tornado Warning', ['url' => $link]);
    app(ChannelDispatcher::class)->send($channel, str_repeat('Severe storms moving east. ', 40), 'Severe Thunderstorm Warning', ['url' => $link]);
    app(SendTestNotification::class)($channel);

    $bodies = collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/notify/'))
        ->map(fn (array $pair): array => $pair[0]->data())->values();

    expect($bodies[0])->toBe(['urls' => chanBlueskyUrl(), 'body' => "Tornado Warning\n\nTornado Warning for\nBoulder County. *bold* & <b> 🌪️\n\n{$link}"])
        ->and(mb_strlen($bodies[1]['body']))->toBeLessThanOrEqual(280)->toBeGreaterThan(250)
        ->and($bodies[1]['body'])->toStartWith("Severe Thunderstorm Warning\n\n")->toEndWith("...\n\n{$link}")
        ->and($bodies[1])->not->toHaveKeys(['title', 'format'])
        ->and(mb_strlen($bodies[2]['body']))->toBeLessThanOrEqual(280)
        ->and($bodies[2]['body'])->toBe(BlueskyChannelType::TEST_FALLBACK);
});

test('the app password never appears outside the encrypted config', function () {
    $user = chanBlueskyPlusUser();
    $this->actingAs($user);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]),
        'plc.directory/*' => Http::response(chanBlueskyDocument()),
        '*/notify/*' => Http::sequence()
            ->push(status: 200)
            ->push(['error' => 'One or more notifications could not be sent'], 424),
    ]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanBlueskyForm());
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'Storm desk']);

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $api = $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)->getJson('/api/v1/channels')->assertOk();

    $places = [
        'apprise_url' => (string) $channel->fresh()->apprise_url,
        'activity log' => json_encode(Activity::all()->toArray()),
        'delivery log' => json_encode($failure->toArray()),
        'edit modal' => json_encode($component->snapshot),
        'api' => $api->getContent(),
        'logs' => implode("\n", $logged),
        'cache key' => BlueskyAccountGuard::cacheKey(CHAN_BSKY_HANDLE),
    ];

    foreach ($places as $place => $content) {
        expect(str_contains(strtolower($content), CHAN_BSKY_PASSWORD))->toBeFalse("the app password leaked into the {$place}");
    }

    foreach (['activity log', 'delivery log'] as $place) {
        expect(str_contains(strtolower($places[$place]), 'wxdesk'))->toBeFalse("the handle leaked into the {$place}");
    }

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/notify/')
        && ! str_contains($request->url(), CHAN_BSKY_PASSWORD) && ! str_contains($request->body(), CHAN_BSKY_PASSWORD));
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => ! str_contains($pair[0]->url(), '/notify/')
        && (str_contains($pair[0]->url(), CHAN_BSKY_PASSWORD) || str_contains($pair[0]->body(), CHAN_BSKY_PASSWORD) || $pair[0]->hasHeader('Authorization'))))->toHaveCount(0);

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($api->json('data.0.destination'))->toBe('@'.CHAN_BSKY_HANDLE)
        ->and($api->json('data.0.destination_label'))->toBe('Bluesky account')
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the summary shows the public handle and the texts are fixed', function () {
    $channel = NotificationChannel::factory()->bluesky()->create();

    expect($this->type->displaySummary($channel))->toBe('@'.CHAN_BSKY_HANDLE)
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'bluesky', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'bluesky', 'apprise_url' => null, 'config' => ['handle' => 'x@evil']])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('Bluesky account')
        ->and($this->type->destinationNoun())->toBe('account')
        ->and(BlueskyChannelType::PASSWORD_HELP)->toContain('App Password')->toContain('never your main account password')
        ->and(BlueskyChannelType::HANDLE_HELP)->toContain('Only accounts hosted by Bluesky are supported')
        ->and(BlueskyChannelType::CONSENT_HELP)->toContain('delete');
});

test('the API refuses to create a bluesky channel', function () {
    $user = chanBlueskyPlusUser();
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'bluesky', ...chanBlueskyForm()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('save-time lookups are limited per subscriber, refused with their own text and without looking anything up', function () {
    $user = chanBlueskyPlusUser();
    $other = chanBlueskyPlusUser();
    RateLimiter::clear('bluesky-lookup:'.$user->id);
    RateLimiter::clear('bluesky-lookup:'.$other->id);
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['error' => 'InvalidRequest'], 400),
        '*' => Http::response(status: 200),
    ]);
    $this->actingAs($user);

    foreach (range(1, 10) as $attempt) {
        expect(fn () => $this->type->buildDestination(chanBlueskyForm(['handle' => "try{$attempt}.example.com"])))
            ->toThrow(InvalidArgumentException::class, BlueskyAccountGuard::HANDLE_NOT_FOUND);
    }
    expect(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(10);

    try {
        $this->type->buildDestination(chanBlueskyForm(['handle' => 'try11.example.com']));
        $this->fail('Expected the throttle refusal.');
    } catch (InvalidArgumentException $refusal) {
        expect($refusal->getMessage())->toMatch('/^Too many attempts\. Try again in \d+ minutes?\.$/')
            ->not->toBe(BlueskyAccountGuard::UNVERIFIED)
            ->not->toBe(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY);
    }
    expect(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(10);

    chanChatFormSubmit('bluesky', chanBlueskyForm(['handle' => 'try12.example.com']))
        ->assertNotified('Too many attempts. Try again in 10 minutes.');
    expect(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(10);

    $this->actingAs($other);
    expect(fn () => $this->type->buildDestination(chanBlueskyForm(['handle' => 'other.example.com'])))
        ->toThrow(InvalidArgumentException::class, BlueskyAccountGuard::HANDLE_NOT_FOUND)
        ->and(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(11);

    $this->actingAs($user);
    $this->travel(11)->minutes();
    expect(fn () => $this->type->buildDestination(chanBlueskyForm(['handle' => 'try13.example.com'])))
        ->toThrow(InvalidArgumentException::class, BlueskyAccountGuard::HANDLE_NOT_FOUND);
});

test('the save limit never applies to sends, and a cached .bsky.social check costs no attempt', function () {
    $user = chanBlueskyPlusUser();
    RateLimiter::clear('bluesky-lookup:'.$user->id);
    $this->actingAs($user);
    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('bluesky-lookup:'.$user->id, 600);
    }
    $channel = NotificationChannel::factory()->bluesky('alerts.example.com')->for($user)->create();
    Http::fake([
        'public.api.bsky.app/*' => Http::response(['did' => CHAN_BSKY_DID]),
        'plc.directory/*' => Http::response(chanBlueskyDocument(handle: 'alerts.example.com')),
        '*/notify/*' => Http::response(status: 200),
    ]);

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert')->success)->toBeTrue();

    Cache::put(BlueskyAccountGuard::cacheKey(CHAN_BSKY_HANDLE), CHAN_BSKY_DID, 600);
    expect($this->type->buildDestination(chanBlueskyForm())['config']['did'])->toBe(CHAN_BSKY_DID);
});

test('a custom-domain handle is verified again on every send, never from the cache', function () {
    $did = CHAN_BSKY_DID;
    Http::fake(function (Request $request) use (&$did) {
        return match (parse_url($request->url(), PHP_URL_HOST)) {
            'public.api.bsky.app' => Http::response(['did' => $did]),
            'plc.directory' => Http::response(chanBlueskyDocument(handle: 'alerts.example.com', did: $did)),
            default => Http::response(status: 200),
        };
    });
    $channel = NotificationChannel::factory()->bluesky('alerts.example.com')->for(chanBlueskyPlusUser())->create();
    $send = fn () => app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert');

    expect($send()->success)->toBeTrue()
        ->and($send()->success)->toBeTrue()
        ->and(chanBlueskyRequestsTo('public.api.bsky.app'))->toBe(2)
        ->and(chanBlueskyRequestsTo('plc.directory'))->toBe(2)
        ->and(Cache::has(BlueskyAccountGuard::cacheKey('alerts.example.com')))->toBeFalse();

    $did = 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz';

    expect($send())->success->toBeFalse()->error->toBe(BlueskyChannelType::ACCOUNT_CHANGED_MESSAGE)
        ->and(chanBlueskyNotifyCount())->toBe(2);

    $did = 'did:web:evil.example';

    expect($send())->success->toBeFalse()->error->toBe(BlueskyAccountGuard::NOT_HOSTED_BY_BLUESKY)
        ->and(chanBlueskyNotifyCount())->toBe(2);
});

test('only handles under .bsky.social are cached', function () {
    expect(BlueskyAccountGuard::isCacheable('wxdesk.bsky.social'))->toBeTrue()
        ->and(BlueskyAccountGuard::isCacheable('a.b.bsky.social'))->toBeTrue()
        ->and(BlueskyAccountGuard::isCacheable('bsky.social'))->toBeFalse()
        ->and(BlueskyAccountGuard::isCacheable('wxdesk.bsky.social.evil.example'))->toBeFalse()
        ->and(BlueskyAccountGuard::isCacheable('evilbsky.social'))->toBeFalse()
        ->and(BlueskyAccountGuard::isCacheable('alerts.example.com'))->toBeFalse();
});

test('the 280-character cap never cuts the View Product link: it is kept whole or dropped', function (int $linkLength, bool $kept) {
    $link = 'https://wx.example.com/view-product/'.str_repeat('a', $linkLength - 36);

    foreach (['alert', 'confirmation'] as $type) {
        $text = $this->type->compose(str_repeat('Severe storms moving east. ', 30), 'Severe Thunderstorm Warning', ['type' => $type, 'url' => $link]);

        expect(mb_strlen($text))->toBeLessThanOrEqual(280)
            ->and(str_contains($text, $link))->toBe($kept)
            ->and(str_contains($text, 'https://wx.example.com/view-product/') === str_contains($text, $link))->toBeTrue("{$type}: a cut link");
    }
})->with([
    'a short link' => [60, true],
    'a 250-character link' => [250, true],
    'a 300-character link' => [300, false],
]);

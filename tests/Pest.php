<?php

use App\Models\ChannelType;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Settings\IntegrationSettings;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Delivery\Pauser;
use App\Support\TraccarUrlGuard;
use Carbon\Carbon;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // A request no test faked fails at once instead of reaching the network and, for an
        // unreachable host, waiting out the client's timeout (10 s each: the Locations Edit page
        // tests alone cost minutes). The app treats a failed request as an unreachable service,
        // so a test that merely renders such a page still passes; one that needs a response must
        // Http::fake() it.
        Http::preventStrayRequests();
        app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => ['93.184.216.34']));
        app()->instance(HeartbeatUrlGuard::class, new HeartbeatUrlGuard(fn (string $host): array => ['93.184.216.34']));
        // No chat server name resolves unless a test says so (chanFakeChatDns), so a guarded
        // messaging type can never reach real DNS: an unknown host is refused as not found.
        chanFakeChatDns([]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
| Pest helper names are global but file load order isn't guaranteed, so helpers shared by
| more than one test file live here rather than in whichever file happened to need them
| first — otherwise running a single file on its own hits an undefined function.
|
*/

function something()
{
    // ..
}

/**
 * One switched-on row of the alert wizard's product table (`product_settings.{category}`), as
 * the form holds it. Overrides replace any key (e.g. `location_ids`, `wfo`, `enabled`).
 *
 * @param  array<string, mixed>  $overrides
 * @return array{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
 */
function alertWizardRow(Product $product, string $mode, array $overrides = []): array
{
    return [
        'product_id' => $product->id,
        'enabled' => true,
        'matching_mode' => $mode,
        'location_ids' => [],
        'wfo' => null,
        'county_zone_codes' => [],
        ...$overrides,
    ];
}

/**
 * Where a product's row sits in its category's list in the alert wizard's form state.
 */
function alertWizardRowIndex(Testable $component, Product $product): int
{
    $index = collect($component->get("data.product_settings.{$product->category->value}"))
        ->search(fn (array $row): bool => (int) $row['product_id'] === $product->id);

    expect($index)->not->toBeFalse();

    return $index;
}

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

/**
 * Fake the endpoint a Spatie webhook call POSTs to, and return the recorded
 * request history. Spatie's CallWebhookJob resolves its HTTP client with
 * app(GuzzleHttp\Client::class), so this binds that — Http::fake() does NOT
 * intercept it.
 *
 * @param  array<int, mixed>  $responses  Guzzle MockHandler queue entries (Response or Throwable).
 * @return ArrayObject<int, array{request: RequestInterface, response: mixed}>
 */
function chanFakeWebhookEndpoint(array $responses): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    app()->bind(Client::class, fn () => new Client(['handler' => $stack]));

    return $history;
}

/**
 * Replace the delivery Pauser with a recording no-op, so the webhook
 * transport's between-attempt waits never actually sleep in a test.
 *
 * When the test has frozen time, the pause is also charged to that frozen
 * clock — the transport reads its wall-clock budget through now(), so a
 * budget test can watch the seconds add up without waiting for any of them.
 *
 * @return ArrayObject<int, int> The seconds asked for, in order.
 */
function chanFakePauser(): ArrayObject
{
    $pauses = new ArrayObject;

    app()->instance(Pauser::class, new class($pauses) extends Pauser
    {
        public function __construct(private ArrayObject $pauses) {}

        public function pause(int $seconds): void
        {
            $this->pauses[] = $seconds;

            if (Carbon::hasTestNow()) {
                Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
            }
        }
    });

    return $pauses;
}

/**
 * Like chanFakeWebhookEndpoint(), but every request also charges its own Guzzle
 * `timeout` to the frozen test clock — i.e. it behaves like an endpoint that
 * always takes the full timeout to answer. Freeze time before calling it.
 *
 * @param  array<int, mixed>  $responses  Guzzle MockHandler queue entries (Response or Throwable).
 * @return ArrayObject<int, array{request: RequestInterface, response: mixed}>
 */
function chanFakeSlowWebhookEndpoint(array $responses): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $stack->push(fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
        Carbon::setTestNow(Carbon::now()->addSeconds((int) ($options['timeout'] ?? 0)));

        return $handler($request, $options);
    });

    app()->bind(Client::class, fn () => new Client(['handler' => $stack]));

    return $history;
}

/**
 * A verified webhook channel whose config is the legacy url+secret pair
 * overlaid with whatever options the test cares about.
 *
 * @param  array<string, mixed>  $config
 */
function chanWebhookChannel(array $config = []): NotificationChannel
{
    return NotificationChannel::factory()->webhook()->create([
        'config' => [...['url' => 'https://hooks.example.com/wx', 'secret' => 's3cret'], ...$config],
    ]);
}

/**
 * Switch on the phone channel types' catalog rows, which ship disabled until the owner's
 * legal review. Pair with a Plus subscriber: the rows are `min_tier = plus`.
 */
function chanEnablePhoneTypes(): void
{
    ChannelType::query()
        ->whereIn('slug', ['twilio_sms', 'bulkvs_sms', 'httpsms', 'twilio_voice'])
        ->update(['enabled' => true]);
}

/**
 * Switch on the WhatsApp catalog row, which ships disabled until the owner's Meta and legal
 * items are done. Pair with a Plus subscriber: the row is `min_tier = plus`.
 */
function chanEnableWhatsApp(): void
{
    ChannelType::query()->where('slug', 'whatsapp')->update(['enabled' => true]);
}

/**
 * Bind ChatServerGuard with a fake DNS: each host name maps to the addresses it resolves to,
 * and any other name resolves to nothing (refused as not found). Names are looked up in
 * lower case, as the guard asks for them.
 *
 * @param  array<string, list<string>>  $hostToAddresses
 */
function chanFakeChatDns(array $hostToAddresses): void
{
    app()->instance(ChatServerGuard::class, new ChatServerGuard(
        fn (string $host): array => $hostToAddresses[$host] ?? [],
    ));
}

const CHAN_VAPID_PUBLIC = 'BFbRVvYSUiZyN3mXneDDJEjejmTXvjDA8TMDLCD_aUW3GKOA-ohT-uHQDGm3pQqeK4YrmmZlfjwE7XyqTVSzAAM';
const CHAN_VAPID_PRIVATE = '0bf_Od9Ew-hOKpMBoxmv3RDDX5vkUu2yCrGiC4OQsMs';

/**
 * Configure the VAPID keys (a throwaway pair from VAPID::createVapidKeys(); the spec example pair is not a matched pair).
 */
function chanConfigureVapid(): void
{
    config(['webpush.vapid.public_key' => CHAN_VAPID_PUBLIC, 'webpush.vapid.private_key' => CHAN_VAPID_PRIVATE, 'webpush.vapid.subject' => 'mailto:test@example.com']);
}

/**
 * A browser PushSubscription JSON. Keys are real-shaped (87-char p256dh, 22-char auth) but not a
 * usable EC point, which is fine because chanFakePushService() never encrypts.
 */
function chanPushSubscriptionJson(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): string
{
    return json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)]]);
}

/**
 * A subscription with a genuine P-256 public point, so the real WebPush can encrypt to it.
 *
 * @return array{endpoint: string, public_key: string, auth_token: string, content_encoding: string}
 */
function chanRealPushSubscriptionConfig(string $endpoint): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key)['ec'];
    $point = "\x04".str_pad($details['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'endpoint' => $endpoint,
        'public_key' => rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
        'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ];
}

/**
 * Replace the push service with a recording double. Failure reports carry the endpoint and key
 * material in their reason, like a real exception-derived reason would, so tests can prove the
 * transport never surfaces it.
 *
 * @return ArrayObject<int, array{subscription: Subscription, payload: ?string}>
 */
function chanFakePushService(bool $success = true, int $status = 201, bool $expired = false): ArrayObject
{
    $sent = new ArrayObject;
    app()->bind(WebPush::class, fn () => new class($sent, $success, $status, $expired) extends WebPush
    {
        public function __construct(private ArrayObject $sent, private bool $ok, private int $status, private bool $expired)
        {
            parent::__construct(['VAPID' => ['subject' => 'mailto:test@example.com', 'publicKey' => CHAN_VAPID_PUBLIC, 'privateKey' => CHAN_VAPID_PRIVATE]]);
        }

        public function sendOneNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): MessageSentReport
        {
            $this->sent[] = ['subscription' => $subscription, 'payload' => $payload];
            $request = new Request('POST', $subscription->getEndpoint());
            $response = new Response($this->expired ? 410 : $this->status, [], 'body '.$subscription->getEndpoint());
            $reason = 'Failed for '.$subscription->getEndpoint().' p256dh='.$subscription->getPublicKey().' auth='.$subscription->getAuthToken();

            return new MessageSentReport($request, $response, $this->ok && ! $this->expired, $reason);
        }
    });

    return $sent;
}

/**
 * Writes a throwaway service-account file, points the app at it and returns the file path and
 * the public key to verify signed JWTs with. Remove the file in afterEach.
 *
 * @return array{path: string, public: string}
 */
function fcmUseCredentials(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);

    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'project_id' => 'wx-test',
        'client_email' => 'push@wx-test.iam.gserviceaccount.com',
        'private_key' => $privatePem,
    ]));

    config(['services.firebase.service_account' => $path]);

    return ['path' => $path, 'public' => openssl_pkey_get_details($key)['key']];
}

/**
 * Fakes Google's token endpoint and the FCM send endpoint. Pass an Http::response() to change
 * what the send endpoint answers.
 */
function fcmFakeGoogle(mixed $sendResponse = null): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => $sendResponse ?? Http::response(['name' => 'projects/wx-test/messages/1']),
    ]);
}

/**
 * Http::fake() calls STACK -- the first matching stub wins, so faking the same
 * URL a second time in one test is silently ignored. Any test that re-fakes
 * (e.g. to try a second scenario after Cache::flush()) must start from a fresh
 * client; every nws* fake helper below does this itself.
 */
function nwsFreshHttp(): void
{
    Http::swap(new Factory);
}

/**
 * Decoded body of an NWS /points response. Overrides replace top-level
 * `properties` keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nwsPoint(array $overrides = []): array
{
    return ['properties' => array_replace([
        'forecast' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast',
        'forecastHourly' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast/hourly',
        'forecastGridData' => 'https://api.weather.gov/gridpoints/BOU/64,73',
        'observationStations' => 'https://api.weather.gov/gridpoints/BOU/64,73/stations',
        'radarStation' => 'KFTG',
        'timeZone' => 'America/Denver',
    ], $overrides)];
}

/**
 * @param  array<int, array{id: string, name?: string, lng?: float, lat?: float, elevation_m?: float}>  $stations
 * @return array<string, mixed>
 */
function nwsStations(array $stations): array
{
    return ['features' => array_map(fn (array $station): array => [
        'geometry' => ['type' => 'Point', 'coordinates' => [$station['lng'] ?? -105.05033, $station['lat'] ?? 40.01169]],
        'properties' => [
            'stationIdentifier' => $station['id'],
            'name' => $station['name'] ?? 'Erie Municipal Airport',
            'elevation' => ['unitCode' => 'wmoUnit:m', 'value' => $station['elevation_m'] ?? 1549.908],
        ],
    ], $stations)];
}

/**
 * Decoded body of an NWS observations/latest response (recursive overrides,
 * so ['temperature' => ['value' => null]] nulls just that value).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nwsObservation(array $overrides = []): array
{
    return ['properties' => array_replace_recursive([
        'timestamp' => '2026-10-02T14:35:00+00:00',
        'textDescription' => 'Clear',
        'icon' => 'https://api.weather.gov/icons/land/day/skc?size=medium',
        'temperature' => ['value' => 12.0, 'unitCode' => 'wmoUnit:degC'],
        'dewpoint' => ['value' => 8.0, 'unitCode' => 'wmoUnit:degC'],
        'windDirection' => ['value' => 0, 'unitCode' => 'wmoUnit:degree_(angle)'],
        'windSpeed' => ['value' => 0.0, 'unitCode' => 'wmoUnit:km_h-1'],
        'windGust' => ['value' => null, 'unitCode' => 'wmoUnit:km_h-1'],
        'barometricPressure' => ['value' => 102510, 'unitCode' => 'wmoUnit:Pa'],
        'seaLevelPressure' => ['value' => null, 'unitCode' => 'wmoUnit:Pa'],
        'relativeHumidity' => ['value' => 76.5, 'unitCode' => 'wmoUnit:percent'],
        'windChill' => ['value' => null, 'unitCode' => 'wmoUnit:degC'],
        'heatIndex' => ['value' => null, 'unitCode' => 'wmoUnit:degC'],
        'visibility' => ['value' => 16090, 'unitCode' => 'wmoUnit:m'],
    ], $overrides)];
}

/**
 * @return array<int, array<string, mixed>>
 */
function nwsForecastPeriods(): array
{
    return [
        ['name' => 'Today', 'startTime' => '2026-10-02T09:00:00-06:00', 'isDaytime' => true, 'temperature' => 79, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'detailedForecast' => 'Sunny, with a high near 79. East southeast wind 2 to 6 mph.', 'icon' => 'https://api.weather.gov/icons/land/day/few?size=medium'],
        ['name' => 'Tonight', 'startTime' => '2026-10-02T18:00:00-06:00', 'isDaytime' => false, 'temperature' => 50, 'temperatureUnit' => 'F', 'shortForecast' => 'Mostly Clear', 'detailedForecast' => 'Mostly clear, with a low around 50. South southwest wind around 7 mph.', 'icon' => 'https://api.weather.gov/icons/land/night/few?size=medium'],
        ['name' => 'Saturday', 'startTime' => '2026-10-03T06:00:00-06:00', 'isDaytime' => true, 'temperature' => 84, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'detailedForecast' => 'Sunny. High near 84, with temperatures falling to around 81 in the afternoon.', 'icon' => 'https://api.weather.gov/icons/land/day/few?size=medium'],
    ];
}

/**
 * Fakes every NWS endpoint the Full Daily Summary touches. $overrides is keyed
 * by the same URL pattern used below and replaces that entry's response.
 *
 * @param  array<string, mixed>  $overrides
 */
function nwsFakeEverything(array $overrides = []): void
{
    nwsFreshHttp();

    $hourly = collect(range(0, 15))->map(function (int $i): array {
        $start = Illuminate\Support\Carbon::parse('2026-10-02T09:00:00-06:00')->addHours($i);

        return [
            'startTime' => $start->toIso8601String(), 'endTime' => $start->copy()->addHour()->toIso8601String(),
            'temperature' => 50 + $i, 'temperatureUnit' => 'F',
            'probabilityOfPrecipitation' => ['value' => 10], 'windSpeed' => '5 mph', 'windDirection' => 'NW',
            'icon' => 'https://api.weather.gov/icons/land/day/few?size=small', 'shortForecast' => 'Sunny',
        ];
    })->all();

    Http::fake(array_replace([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response(['properties' => ['periods' => $hourly]]),
        'api.weather.gov/gridpoints/BOU/64,73/forecast' => Http::response(['properties' => ['periods' => nwsForecastPeriods()]]),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response(nwsStations([['id' => 'KEIK']])),
        'api.weather.gov/stations/KEIK/observations/latest' => Http::response(nwsObservation()),
        'radar.weather.gov/*' => Http::response("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 200, ['Content-Type' => 'image/gif']),
    ], $overrides));
}

/**
 * A representative BuildDailySummaryReport array. Overrides replace top-level keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function presenterReport(array $overrides = []): array
{
    return array_replace([
        'location' => 'Home',
        'timezone' => 'America/Denver',
        'sun' => ['sunrise' => '2026-10-02T06:57:34-06:00', 'sunset' => '2026-10-02T18:40:53-06:00'],
        'alerts' => [['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'expires' => '2026-10-02T15:00:00-06:00', 'family' => 'advisory']],
        'current' => [
            'station' => ['id' => 'KEIK', 'name' => 'Erie Municipal Airport', 'lat' => 40.01169, 'lng' => -105.05033, 'elevation_ft' => 5085.0],
            'observed_at' => '2026-10-02T08:35:00-06:00', 'description' => 'Clear',
            'icon' => ['period' => 'day', 'code' => 'skc'],
            'temperature_f' => 53.6, 'feels_like_f' => 50.0, 'humidity_pct' => 76.5,
            'wind' => ['direction' => 'NE', 'speed_mph' => 10.0, 'gust_mph' => 20.0],
            'barometer_inhg' => 30.2712, 'dewpoint_f' => 46.4, 'visibility_mi' => 10.0,
        ],
        'radar_site' => 'KFTG',
        'hourly' => [['time' => '2026-10-02T09:00:00-06:00', 'icon' => ['period' => 'day', 'code' => 'few'], 'temperature' => 54, 'unit' => 'F', 'precip_chance' => 10, 'wind' => '5 mph NW', 'summary' => 'Sunny']],
        'periods' => [['name' => 'Today', 'date' => '2026-10-02T09:00:00-06:00', 'summary' => 'Sunny, with a high near 79. East southeast wind 2 to 6 mph.', 'short' => 'Sunny', 'label' => 'High', 'temperature' => 79, 'unit' => 'F', 'icon' => ['period' => 'day', 'code' => 'few']]],
    ], $overrides);
}

/**
 * The bodies of the notifications Filament has queued for the current user, as one string.
 * Reading them consumes them, so call it once and don't combine it with assertNotified().
 */
function sentNotificationBodies(): string
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications
        ->map(fn (Notification $notification): string => (string) $notification->getBody())
        ->implode(' ');
}

/**
 * Save one IntegrationSettings property (an integration's toggle or endpoint).
 */
function setIntegrationSetting(string $property, mixed $value): void
{
    $settings = app(IntegrationSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

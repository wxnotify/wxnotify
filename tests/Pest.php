<?php

use Filament\Schemas\Components\Wizard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

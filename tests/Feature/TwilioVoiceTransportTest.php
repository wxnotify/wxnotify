<?php

use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\Transports\TwilioVoiceTransport;
use App\Support\Delivery\VoiceScript;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const CHAN_VOICE_SID = 'AC0123456789abcdef0123456789abcdef';

const CHAN_VOICE_TOKEN = 'fedcba9876543210fedcba9876543210';

const CHAN_VOICE_CALLS_URL = 'https://api.twilio.com/2010-04-01/Accounts/'.CHAN_VOICE_SID.'/Calls.json';

/**
 * A verified Twilio voice channel owned by a Plus subscriber.
 *
 * @param  array<string, mixed>  $config
 */
function chanTwilioVoiceChannel(array $config = []): NotificationChannel
{
    $channel = NotificationChannel::factory()
        ->twilioVoice()
        ->for(User::factory()->create(['tier_override' => Tier::Plus]))
        ->create();

    $channel->forceFill(['config' => array_merge($channel->config, [
        'account_sid' => CHAN_VOICE_SID,
        'auth_token' => CHAN_VOICE_TOKEN,
        'from' => '+15552340001',
        'to' => '+15555550123',
    ], $config)])->save();

    return $channel;
}

function chanSendVoice(NotificationChannel $channel, array $meta = []): DeliveryResult
{
    return app(TwilioVoiceTransport::class)->send($channel, 'A tornado was reported near Boulder. Take cover.', 'Tornado Warning', $meta);
}

test('it posts to the account\'s calls endpoint with basic auth and form fields', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('0', 32)], 201)]);

    chanSendVoice(chanTwilioVoiceChannel());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === CHAN_VOICE_CALLS_URL
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(CHAN_VOICE_SID.':'.CHAN_VOICE_TOKEN))
        && $request->isForm()
        && $request['To'] === '+15555550123'
        && $request['From'] === '+15552340001'
        && $request['Twiml'] === VoiceScript::compose('A tornado was reported near Boulder. Take cover.', 'Tornado Warning', [])
        && array_keys($request->data()) === ['To', 'From', 'Twiml']);
});

test('the request does not follow redirects and has the fixed timeouts', function () {
    $options = null;
    Http::fake(['api.twilio.com/*' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(status: 201);
    }]);

    chanSendVoice(chanTwilioVoiceChannel());

    expect(TwilioVoiceTransport::ENDPOINT)->toBe('https://api.twilio.com/2010-04-01/Accounts/%s/Calls.json')
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['connect_timeout'])->toBe(TwilioVoiceTransport::CONNECT_TIMEOUT)
        ->and($options['timeout'])->toBe(TwilioVoiceTransport::TIMEOUT)
        ->and(TwilioVoiceTransport::CONNECT_TIMEOUT)->toBe(5)
        ->and(TwilioVoiceTransport::TIMEOUT)->toBe(10);
});

test('a 201 is a delivered call', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('0', 32), 'status' => 'queued'], 201)]);

    $result = chanSendVoice(chanTwilioVoiceChannel());

    expect($result->success)->toBeTrue()->and($result->error)->toBeNull();
});

test('known twilio error codes map to fixed text', function (int $status, ?int $code, string $expected) {
    Http::fake(['api.twilio.com/*' => Http::response(array_filter([
        'code' => $code,
        'message' => 'The number +15555550123 is unverified for '.CHAN_VOICE_SID,
        'more_info' => 'https://www.twilio.com/docs/errors/'.$code,
        'status' => $status,
    ]), $status)]);

    $result = chanSendVoice(chanTwilioVoiceChannel());

    expect($result->success)->toBeFalse()->and($result->error)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    '401 without a code' => [401, null, 'Twilio refused the Account SID or auth token.'],
    '20003 permission denied' => [401, 20003, 'Twilio refused the Account SID or auth token.'],
    '21211 invalid to' => [400, 21211, 'Twilio cannot call this number.'],
    '21214 to cannot be reached' => [400, 21214, 'Twilio cannot call this number.'],
    '21217 to not valid' => [400, 21217, 'Twilio cannot call this number.'],
    '21210 from not verified' => [400, 21210, 'The From number is not a voice number on this Twilio account.'],
    '21212 invalid from' => [400, 21212, 'The From number is not a voice number on this Twilio account.'],
    '21219 trial account' => [400, 21219, 'This Twilio trial account can only call numbers verified in Twilio.'],
    '21215 geographic permissions' => [400, 21215, 'Twilio\'s geographic permissions on this account do not allow calling this number.'],
    'an unknown code' => [400, 21205, 'Twilio refused the call (HTTP 400).'],
    'a 404 with no code' => [404, null, 'Twilio refused the call (HTTP 404).'],
    'a redirect' => [302, null, 'Twilio refused the call (HTTP 302).'],
    'a 200 instead of a 201' => [200, null, 'Twilio refused the call (HTTP 200).'],
]);

test('a twilio code is read only as an integer', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => '21211', 'message' => 'x'], 400)]);

    expect(chanSendVoice(chanTwilioVoiceChannel())->error)->toBe('Twilio refused the call (HTTP 400).');
});

test('a non-json error body is a status-only failure', function () {
    Http::fake(['api.twilio.com/*' => Http::response('<html>'.CHAN_VOICE_SID.'</html>', 400)]);

    expect(chanSendVoice(chanTwilioVoiceChannel())->error)->toBe('Twilio refused the call (HTTP 400).');
});

test('a 429 and a 500 are one attempt and a failure', function (int $status, string $expected) {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 20429, 'message' => 'Too many requests', 'status' => $status], $status)]);

    $result = chanSendVoice(chanTwilioVoiceChannel());

    expect($result->success)->toBeFalse()->and($result->error)->toBe($expected);
    Http::assertSentCount(1);
})->with([
    '429' => [429, 'Twilio is rate limiting this account; the call was not retried.'],
    '500' => [500, 'Twilio had an error (HTTP 500); the call was not retried.'],
    '503' => [503, 'Twilio had an error (HTTP 503); the call was not retried.'],
]);

test('a timeout is a failure, not an exception, and nothing is retried', function () {
    $attempts = 0;
    Http::fake(['api.twilio.com/*' => function (Request $request) use (&$attempts) {
        $attempts++;

        return Http::failedConnection('cURL error 28: Operation timed out for '.CHAN_VOICE_CALLS_URL)($request);
    }]);
    Log::spy();

    $result = chanSendVoice(chanTwilioVoiceChannel());

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Twilio did not answer in time; a call may still have been placed.')
        ->and($attempts)->toBe(1);
    Log::shouldHaveReceived('warning')->once()->with('Twilio voice call failed: '.ConnectionException::class);
});

test('no exception escapes the dispatcher and the call is placed once', function () {
    $attempts = 0;
    Http::fake(['api.twilio.com/*' => function (Request $request) use (&$attempts) {
        $attempts++;

        return Http::failedConnection()($request);
    }]);
    $result = app(ChannelDispatcher::class)->send(chanTwilioVoiceChannel(), 'Tornado Warning', 'Alert', ['type' => 'alert']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Twilio did not answer in time; a call may still have been placed.')
        ->and($attempts)->toBe(1);
});

test('a malformed account sid never reaches the network', function (string $sid) {
    Http::fake();

    $result = chanSendVoice(chanTwilioVoiceChannel(['account_sid' => $sid]));

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(TwilioVoiceTransport::INVALID_SID)
        ->and($result->error)->not->toContain($sid);
    Http::assertNothingSent();
})->with([
    'a path escape' => ['AC0123456789abcdef0123456789abcdef/../../Messages'],
    'a query string' => ['AC0123456789abcdef0123456789abcdef?x=1'],
    'one character short' => [substr(CHAN_VOICE_SID, 0, -1)],
    'an API key sid' => ['SK'.substr(CHAN_VOICE_SID, 2)],
    'a non-hex character' => [substr(CHAN_VOICE_SID, 0, -1).'g'],
    'a trailing newline' => [CHAN_VOICE_SID."\n"],
]);

test('an upper-case account sid is accepted', function () {
    Http::fake(['api.twilio.com/*' => Http::response(status: 201)]);
    $sid = 'AC0123456789ABCDEF0123456789ABCDEF';

    expect(chanSendVoice(chanTwilioVoiceChannel(['account_sid' => $sid]))->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === sprintf(TwilioVoiceTransport::ENDPOINT, $sid));
});

test('missing or invalid settings fail before any HTTP', function (array $config) {
    Http::fake();

    $result = chanSendVoice(chanTwilioVoiceChannel($config));

    expect($result->success)->toBeFalse()->and($result->error)->toBe(TwilioVoiceTransport::MISSING_SETTINGS);
    Http::assertNothingSent();
})->with([
    'no auth token' => [['auth_token' => '']],
    'no from' => [['from' => null]],
    'no to' => [['to' => ' ']],
]);

test('a stored number that is not a valid NANP number fails with its own text before any HTTP', function (array $config) {
    Http::fake();

    $result = chanSendVoice(chanTwilioVoiceChannel($config));

    expect($result->success)->toBeFalse()->and($result->error)->toBe(TwilioVoiceTransport::INVALID_NUMBERS)
        ->and(TwilioVoiceTransport::INVALID_NUMBERS)->toBe('This voice channel\'s phone numbers are not valid.');
    Http::assertNothingSent();
})->with([
    'a to that is not a NANP number' => [['to' => '+442071234567']],
    'a from that is not a NANP number' => [['from' => '12345']],
    'a to with a 1 exchange' => [['to' => '+15551230123']],
]);

test('a channel of another type is refused with a fixed text before any HTTP', function () {
    Http::fake();
    $sms = NotificationChannel::factory()->twilioSms()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(TwilioVoiceTransport::class)->send($sms, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This channel cannot place voice calls.');
    Http::assertNothingSent();
});

test('a guzzle transfer error is the no-answer failure after exactly one attempt', function () {
    $attempts = 0;
    Http::fake(['api.twilio.com/*' => function () use (&$attempts) {
        $attempts++;

        throw new TransferException('x');
    }]);

    $result = chanSendVoice(chanTwilioVoiceChannel());

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(TwilioVoiceTransport::NO_ANSWER)
        ->and($attempts)->toBe(1);
});

test('no error text contains the sid, token, number or response body', function (Closure $response) {
    Http::fake(['api.twilio.com/*' => $response]);
    Log::spy();

    $result = chanSendVoice(chanTwilioVoiceChannel());

    $secrets = [CHAN_VOICE_SID, CHAN_VOICE_TOKEN, '+15555550123', '5555550123', '+15552340001', '5552340001', 'LEAKED-BODY'];

    foreach ($secrets as $secret) {
        expect((string) $result->error)->not->toContain($secret);
    }

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning', [Mockery::on(function (string $message) use ($secrets): bool {
        foreach ($secrets as $secret) {
            if (str_contains($message, $secret)) {
                return true;
            }
        }

        return false;
    })]);
})->with([
    'a 400 echoing the request' => [fn () => fn () => Http::response(['code' => 99999, 'message' => 'LEAKED-BODY '.CHAN_VOICE_SID.' '.CHAN_VOICE_TOKEN.' +15555550123'], 400)],
    'a 500 html page' => [fn () => fn () => Http::response('LEAKED-BODY +15552340001', 500)],
    'a timeout naming the url' => [fn () => fn (Request $request) => Http::failedConnection('LEAKED-BODY '.CHAN_VOICE_CALLS_URL.' '.CHAN_VOICE_TOKEN)($request)],
]);

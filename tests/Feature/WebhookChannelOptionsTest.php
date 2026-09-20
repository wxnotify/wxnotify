<?php

use App\Models\NotificationChannel;
use App\Rules\SafeWebhookHeaders;
use App\Rules\SafeWebhookProxyUrl;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\WebhookTransport;
use App\Support\Delivery\WebhookHeaderGuard;
use App\Support\Delivery\WebhookUrlGuard;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Validator;

// chanFakeWebhookEndpoint(), chanFakePauser() and chanWebhookChannel() live in tests/Pest.php.

beforeEach(function () {
    // Every hostname resolves to a public address unless a test says otherwise.
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
    $this->pauses = chanFakePauser();
});

/*
|--------------------------------------------------------------------------
| Config defaults
|--------------------------------------------------------------------------
*/

test('a legacy url-and-secret config reads back with every option at its default', function () {
    expect(WebhookChannelType::withDefaults(['url' => 'https://hooks.example.com/wx', 'secret' => 's3cret']))->toBe([
        'url' => 'https://hooks.example.com/wx',
        'method' => 'POST',
        'sign' => true,
        'secret' => 's3cret',
        'verify_ssl' => true,
        'use_timestamp' => true,
        'tries' => 3,
        'timeout' => 5,
        'headers' => [],
        'use_proxy' => false,
        'proxy_url' => null,
    ]);
});

test('withDefaults normalises and clamps hostile stored values', function () {
    $config = WebhookChannelType::withDefaults([
        'url' => 'https://hooks.example.com/wx',
        'method' => 'delete',
        'tries' => 99,
        'timeout' => 0,
        'headers' => null,
        'sign' => 'yes',
    ]);

    expect($config['method'])->toBe('POST')
        ->and($config['tries'])->toBe(5)
        ->and($config['timeout'])->toBe(1)
        ->and($config['headers'])->toBe([])
        ->and($config['sign'])->toBeTrue();
});

test('building the destination from the wizard keeps every chosen option', function () {
    $config = (new WebhookChannelType)->buildDestination([
        'url' => 'https://hooks.example.com/wx',
        'method' => 'PUT',
        'sign' => true,
        'secret' => str_repeat('a', 64),
        'verify_ssl' => false,
        'use_timestamp' => false,
        'tries' => 2,
        'timeout' => 9,
        'headers' => ['X-Api-Key' => 'abc'],
        'use_proxy' => true,
        'proxy_url' => 'http://proxy.example.com:3128',
    ])['config'];

    expect($config)->toBe([
        'url' => 'https://hooks.example.com/wx',
        'method' => 'PUT',
        'sign' => true,
        'secret' => str_repeat('a', 64),
        'verify_ssl' => false,
        'use_timestamp' => false,
        'tries' => 2,
        'timeout' => 9,
        'headers' => ['X-Api-Key' => 'abc'],
        'use_proxy' => true,
        'proxy_url' => 'http://proxy.example.com:3128',
    ]);
});

test('a proxy url is dropped when the proxy toggle is off, and a missing secret is generated', function () {
    $config = WebhookChannelType::configFromForm([
        'url' => 'https://hooks.example.com/wx',
        'use_proxy' => false,
        'proxy_url' => 'http://proxy.example.com:3128',
    ]);

    expect($config['proxy_url'])->toBeNull()
        ->and($config['secret'])->toMatch('/^[0-9a-f]{64}$/');
});

test('configFromForm merges into the existing config instead of replacing it', function () {
    $existing = WebhookChannelType::withDefaults([
        'url' => 'https://hooks.example.com/wx',
        'secret' => 's3cret',
        'headers' => ['X-Api-Key' => 'abc'],
    ]);

    // Sign is off, so the secret field was hidden and never submitted.
    $merged = WebhookChannelType::configFromForm(['label' => 'x', 'sign' => false], $existing);

    expect($merged['secret'])->toBe('s3cret')
        ->and($merged['url'])->toBe('https://hooks.example.com/wx')
        ->and($merged['headers'])->toBe(['X-Api-Key' => 'abc'])
        ->and($merged['sign'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The "what we send" description
|--------------------------------------------------------------------------
*/

test('the payload description names exactly the keys a real alert and confirmation payload carry', function () {
    $history = chanFakeWebhookEndpoint([new Response(200), new Response(200)]);
    $channel = chanWebhookChannel();

    app(ChannelDispatcher::class)->send($channel, 'body', 'title', ['type' => 'alert', 'rule' => 'My rule', 'product' => 'Tornado Warning']);
    app(ChannelDispatcher::class)->send($channel, 'body', 'title', ['type' => 'confirmation', 'code' => '123456']);

    $alert = json_decode((string) $history[0]['request']->getBody(), true);
    $confirmation = json_decode((string) $history[1]['request']->getBody(), true);

    expect(array_keys($alert))->toBe(WebhookTransport::ALERT_PAYLOAD_KEYS)
        ->and(array_keys($confirmation))->toBe(WebhookTransport::CONFIRMATION_PAYLOAD_KEYS)
        ->and(array_keys($alert['alert']))->toBe(['rule', 'product']);
});

test('the payload description spells out every documented key and how GET differs', function () {
    $callout = collect((new WebhookChannelType)->formSchema())->firstWhere(fn ($c) => $c->getKey(isAbsolute: false) === 'payload_description');

    expect($callout)->not->toBeNull();

    $description = (string) $callout->getDescription();

    foreach ([...WebhookTransport::ALERT_PAYLOAD_KEYS, WebhookTransport::CONFIRMATION_ONLY_KEY, 'rule', 'product'] as $key) {
        expect($description)->toContain("<code>{$key}</code>");
    }

    expect($description)->toContain('GET')
        ->and($description)->toContain('query string')
        ->and($description)->toContain('ISO 8601');
});

/*
|--------------------------------------------------------------------------
| Header validation
|--------------------------------------------------------------------------
*/

test('ordinary custom headers are accepted', function () {
    expect(app(WebhookHeaderGuard::class)->violation([
        'X-Api-Key' => 'abc123',
        'Authorization' => 'Bearer token',
        'User-Agent' => 'WxNotify/1.0',
        'X-Custom_Thing.1' => '',
    ]))->toBeNull();
});

test('an empty header set is accepted', function () {
    expect(app(WebhookHeaderGuard::class)->violation([]))->toBeNull()
        ->and(app(WebhookHeaderGuard::class)->violation(null))->toBeNull();
});

test('headers the transport controls itself are refused whatever their casing', function (string $name) {
    expect(app(WebhookHeaderGuard::class)->violation([$name => 'x']))->not->toBeNull();
})->with([
    'Host', 'host', 'HOST',
    'Content-Length', 'content-length',
    'Content-Type', 'CONTENT-TYPE',
    'Transfer-Encoding', 'transfer-encoding',
    'Connection', 'Upgrade', 'TE', 'te', 'Trailer',
    'Proxy-Authorization', 'proxy-authorization',
    'Proxy-Connection', 'PROXY-CONNECTION',
    'Signature', 'signature', 'Timestamp', 'TIMESTAMP',
]);

test('the configured signature and timestamp header names are the ones refused', function () {
    config(['webhook-server.signature_header_name' => 'X-Wx-Sig', 'webhook-server.timestamp_header_name' => 'X-Wx-Ts']);

    expect(app(WebhookHeaderGuard::class)->violation(['x-wx-sig' => 'x']))->not->toBeNull()
        ->and(app(WebhookHeaderGuard::class)->violation(['X-WX-TS' => 'x']))->not->toBeNull()
        // The package defaults are no longer reserved once they've been renamed.
        ->and(app(WebhookHeaderGuard::class)->violation(['Signature' => 'x']))->toBeNull();
});

test('a header name that is not an RFC 7230 token is refused', function (string $name) {
    expect(app(WebhookHeaderGuard::class)->violation([$name => 'x']))->not->toBeNull();
})->with(['X Api Key', 'X-Api:Key', 'X-Api@Key', 'X/Api', "X\nApi", '']);

test('a header value carrying a control character is refused and the value is never echoed', function (string $value) {
    $violation = app(WebhookHeaderGuard::class)->violation(['X-Api-Key' => $value]);

    expect($violation)->not->toBeNull()
        ->and($violation)->not->toContain('smuggled')
        ->and($violation)->toContain('X-Api-Key');
})->with([
    "smuggled\r\nX-Injected: 1",
    "smuggled\nX-Injected: 1",
    "smuggled\r",
    "smuggled\x00",
    "smuggled\x7f",
    "smuggled\t",
]);

test('a header value longer than 1024 characters is refused without echoing it', function () {
    $violation = app(WebhookHeaderGuard::class)->violation(['X-Api-Key' => str_repeat('s', 1025)]);

    expect($violation)->not->toBeNull()->and($violation)->not->toContain('ssss');
    expect(app(WebhookHeaderGuard::class)->violation(['X-Api-Key' => str_repeat('s', 1024)]))->toBeNull();
});

test('more than twenty header rows are refused', function () {
    $headers = [];
    for ($i = 1; $i <= 21; $i++) {
        $headers["X-H{$i}"] = 'v';
    }

    expect(app(WebhookHeaderGuard::class)->violation($headers))->not->toBeNull()
        ->and(app(WebhookHeaderGuard::class)->violation(array_slice($headers, 0, 20, true)))->toBeNull();
});

test('the headers form rule rejects a denied header and never echoes its value', function () {
    $validator = Validator::make(['headers' => ['Content-Type' => 'text/x-secret-value']], ['headers' => [new SafeWebhookHeaders]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('headers'))->not->toContain('text/x-secret-value');
});

/*
|--------------------------------------------------------------------------
| Proxy guard
|--------------------------------------------------------------------------
*/

test('a public http or https proxy on an explicit high port is accepted', function (string $url) {
    expect(app(WebhookUrlGuard::class)->proxyViolation($url))->toBeNull();
})->with([
    'http://proxy.example.com:3128',
    'http://proxy.example.com:8080',
    'https://proxy.example.com:8443',
    'http://proxy.example.com',
    'https://proxy.example.com',
    'http://proxy.example.com:80',
    'https://proxy.example.com:443',
]);

test('proxy credentials in the url are allowed', function () {
    expect(app(WebhookUrlGuard::class)->proxyViolation('http://user:pa%3Ass@proxy.example.com:3128'))->toBeNull();
});

test('a proxy url that is not http or https is refused', function (string $url) {
    expect(app(WebhookUrlGuard::class)->proxyViolation($url))->not->toBeNull();
})->with(['ftp://proxy.example.com:3128', 'socks5://proxy.example.com:1080', 'proxy.example.com:3128', '', 'https://']);

test('a proxy on a low service port is refused but 80 and 443 stay usable', function () {
    $guard = app(WebhookUrlGuard::class);

    expect($guard->proxyViolation('http://proxy.example.com:22'))->not->toBeNull()
        ->and($guard->proxyViolation('http://proxy.example.com:25'))->not->toBeNull()
        ->and($guard->proxyViolation('http://proxy.example.com:1023'))->not->toBeNull()
        ->and($guard->proxyViolation('http://proxy.example.com:1024'))->toBeNull()
        ->and($guard->proxyViolation('http://proxy.example.com:80'))->toBeNull()
        ->and($guard->proxyViolation('http://proxy.example.com:443'))->toBeNull();
});

test('a proxy pointing at a private, loopback, metadata or ipv6-disguised address is refused', function (string $url) {
    expect(app(WebhookUrlGuard::class)->proxyViolation($url))->not->toBeNull();
})->with([
    'http://127.0.0.1:3128',
    'http://10.0.0.5:3128',
    'http://192.168.1.1:8080',
    'http://169.254.169.254:80',
    'http://100.64.0.1:3128',
    'http://localhost:3128',
    'http://proxy.internal:3128',
    'http://metadata.google.internal:3128',
    'http://[::1]:3128',
    'http://[::ffff:7f00:1]:3128',
    'http://[::ffff:127.0.0.1]:3128',
    'http://[fc00::1]:3128',
    'http://[2002:a00:1::]:3128',
]);

test('a proxy host that resolves to a private address is refused even when its name looks public', function () {
    $guard = new WebhookUrlGuard(fn () => ['10.0.0.5']);

    expect($guard->proxyViolation('http://proxy.example.com:3128'))->not->toBeNull();
});

test('a proxy host that cannot be resolved fails closed', function () {
    $guard = new WebhookUrlGuard(fn () => []);

    expect($guard->proxyViolation('http://proxy.example.com:3128'))->not->toBeNull();
});

test('a self-hosted install with private networks allowed may use a private proxy', function () {
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);

    expect(app(WebhookUrlGuard::class)->proxyViolation('http://10.0.0.5:3128'))->toBeNull()
        // The scheme and port rules still apply.
        ->and(app(WebhookUrlGuard::class)->proxyViolation('socks5://10.0.0.5:1080'))->not->toBeNull()
        ->and(app(WebhookUrlGuard::class)->proxyViolation('http://10.0.0.5:22'))->not->toBeNull();
});

test('a proxy guard message never repeats the proxy url back', function () {
    $violation = app(WebhookUrlGuard::class)->proxyViolation('http://user:hunter2@10.0.0.5:3128');

    expect($violation)->not->toBeNull()
        ->and($violation)->not->toContain('hunter2')
        ->and($violation)->not->toContain('10.0.0.5');
});

test('the proxy form rule surfaces the guard violation', function () {
    $validator = Validator::make(['proxy_url' => 'http://127.0.0.1:3128'], ['proxy_url' => [new SafeWebhookProxyUrl]]);

    expect($validator->fails())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Transport: each option reaches the request
|--------------------------------------------------------------------------
*/

test('POST, PUT and PATCH send the json payload in the body', function (string $method) {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['method' => $method]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body text', 'title text');
    $request = $history[0]['request'];

    expect($result->success)->toBeTrue()
        ->and($request->getMethod())->toBe($method)
        ->and(json_decode((string) $request->getBody(), true)['body'])->toBe('body text');
})->with(['POST', 'PUT', 'PATCH']);

test('a GET puts the payload in the query string, sends no body, and still signs the json payload', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['method' => 'GET']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body text', 'title text');
    $request = $history[0]['request'];
    $query = urldecode($request->getUri()->getQuery());

    expect($result->success)->toBeTrue()
        ->and($request->getMethod())->toBe('GET')
        ->and((string) $request->getBody())->toBe('')
        ->and($query)->toContain('body=body text')
        ->and($query)->toContain('title=title text')
        ->and($request->getHeaderLine('Signature'))->not->toBe('');
});

test('turning signing off sends no signature header and needs no secret', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['sign' => false, 'secret' => null]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeTrue()
        ->and($history[0]['request']->hasHeader('Signature'))->toBeFalse();
});

test('signing on produces an HMAC over the exact raw body', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['sign' => true, 'secret' => 's3cret']);

    app(ChannelDispatcher::class)->send($channel, 'body');
    $request = $history[0]['request'];

    expect($request->getHeaderLine('Signature'))->toBe(hash_hmac('sha256', (string) $request->getBody(), 's3cret'));
});

test('the verify ssl option reaches Guzzle', function () {
    $history = chanFakeWebhookEndpoint([new Response(200), new Response(200)]);

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['verify_ssl' => false]), 'body');
    app(ChannelDispatcher::class)->send(chanWebhookChannel(['verify_ssl' => true]), 'body');

    expect($history[0]['options']['verify'])->toBeFalse()
        ->and($history[1]['options']['verify'])->toBeTrue();
});

test('the timestamp header is present by default and absent when the option is off', function () {
    $history = chanFakeWebhookEndpoint([new Response(200), new Response(200)]);

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['use_timestamp' => true]), 'body');
    app(ChannelDispatcher::class)->send(chanWebhookChannel(['use_timestamp' => false]), 'body');

    expect($history[0]['request']->getHeaderLine('Timestamp'))->toMatch('/^\d+$/')
        ->and($history[1]['request']->hasHeader('Timestamp'))->toBeFalse();
});

test('the timeout option reaches Guzzle', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['timeout' => 17]), 'body');

    expect($history[0]['options']['timeout'])->toBe(17);
});

test('extra headers are sent alongside the ones the transport controls', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['headers' => ['X-Api-Key' => 'abc123', 'Authorization' => 'Bearer t']]);

    app(ChannelDispatcher::class)->send($channel, 'body');
    $request = $history[0]['request'];

    expect($request->getHeaderLine('X-Api-Key'))->toBe('abc123')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer t')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('Signature'))->not->toBe('');
});

test('the proxy option reaches Guzzle when the proxy toggle is on', function () {
    $history = chanFakeWebhookEndpoint([new Response(200), new Response(200)]);

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['use_proxy' => true, 'proxy_url' => 'http://proxy.example.com:3128']), 'body');
    app(ChannelDispatcher::class)->send(chanWebhookChannel(['use_proxy' => false, 'proxy_url' => 'http://proxy.example.com:3128']), 'body');

    expect($history[0]['options']['proxy'])->toBe('http://proxy.example.com:3128')
        ->and($history[1]['options']['proxy'] ?? null)->toBeNull();
});

test('a stored proxy is re-checked at send time and a now-private proxy is never used', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['use_proxy' => true, 'proxy_url' => 'http://proxy.example.com:3128']);
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and(count($history))->toBe(0);
});

test('stored headers that are no longer valid fail the delivery with a fixed string', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['headers' => ['X-Api-Key' => "leaked\r\nX-Injected: 1"]]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()
        ->and(count($history))->toBe(0)
        ->and($result->error)->not->toContain('leaked')
        ->and($result->error)->not->toContain('X-Injected');
});

test('a channel set to use a proxy with no proxy url fails before any request', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['use_proxy' => true, 'proxy_url' => null]);

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse()
        ->and(count($history))->toBe(0);
});

test('a redirect is still refused when options are in play', function () {
    $history = chanFakeWebhookEndpoint([new Response(302, ['Location' => 'http://10.0.0.5/latest/meta-data/'])]);
    $channel = chanWebhookChannel(['method' => 'PUT', 'headers' => ['X-Api-Key' => 'abc']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect(count($history))->toBe(1)
        ->and($result->success)->toBeFalse()
        ->and($result->error)->not->toContain('10.0.0.5');
});

test('no error string ever carries the endpoint, secret, header values or proxy url', function () {
    chanFakeWebhookEndpoint([new Response(500, [], 'echoing s3cret and hunter2')]);
    $channel = chanWebhookChannel([
        'url' => 'https://hooks.example.com/wx?token=abc123',
        'secret' => 's3cret',
        'tries' => 1,
        'headers' => ['X-Api-Key' => 'headervalue'],
        'use_proxy' => true,
        'proxy_url' => 'http://user:hunter2@proxy.example.com:3128',
    ]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('500')
        ->and($result->error)->not->toContain('s3cret')
        ->and($result->error)->not->toContain('abc123')
        ->and($result->error)->not->toContain('headervalue')
        ->and($result->error)->not->toContain('hunter2')
        ->and($result->error)->not->toContain('proxy.example.com')
        ->and($result->error)->not->toContain('hooks.example.com');
});

/*
|--------------------------------------------------------------------------
| Transport: retries inside the delivery
|--------------------------------------------------------------------------
*/

test('a 500 followed by a success is delivered on the second attempt after a one second pause', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body');

    expect($result->success)->toBeTrue()
        ->and(count($history))->toBe(2)
        ->and($this->pauses->getArrayCopy())->toBe([1]);
});

test('a connection error is retried', function () {
    $request = new Request('POST', 'https://hooks.example.com/wx');
    $history = chanFakeWebhookEndpoint([new ConnectException('boom', $request), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body');

    expect($result->success)->toBeTrue()->and(count($history))->toBe(2);
});

test('408 and 429 are retried', function (int $status) {
    $history = chanFakeWebhookEndpoint([new Response($status), new Response(200)]);

    expect(app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body')->success)->toBeTrue()
        ->and(count($history))->toBe(2);
})->with([408, 429]);

test('an ordinary 4xx is never retried', function (int $status) {
    $history = chanFakeWebhookEndpoint([new Response($status), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain((string) $status)
        ->and(count($history))->toBe(1)
        ->and($this->pauses->getArrayCopy())->toBe([]);
})->with([400, 401, 403, 404, 422]);

test('a refused redirect is never retried', function () {
    $history = chanFakeWebhookEndpoint([new Response(302, ['Location' => 'https://elsewhere.example.com']), new Response(200)]);

    expect(app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body')->success)->toBeFalse()
        ->and(count($history))->toBe(1);
});

test('retrying stops at the configured number of tries and reports the final attempt', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(500), new Response(503), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 3]), 'body');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('503')
        ->and(count($history))->toBe(3)
        // One second after the first failure, three after each later one.
        ->and($this->pauses->getArrayCopy())->toBe([1, 3]);
});

test('five tries pause one second then three seconds thereafter', function () {
    chanFakeWebhookEndpoint(array_fill(0, 5, new Response(500)));

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5]), 'body');

    expect($this->pauses->getArrayCopy())->toBe([1, 3, 3, 3]);
});

test('one try means exactly one attempt and no pause', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 1]), 'body');

    expect($result->success)->toBeFalse()
        ->and(count($history))->toBe(1)
        ->and($this->pauses->getArrayCopy())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Transport: the wall-clock delivery budget
|--------------------------------------------------------------------------
|
| Time is frozen and moved only by the fake pauser and the slow endpoint, so
| these assert real seconds without waiting for any of them.
*/

test('the slowest possible channel never holds its caller past the delivery budget', function () {
    Carbon::setTestNow(Carbon::now());
    $startedAt = Carbon::now();
    $history = chanFakeSlowWebhookEndpoint(array_fill(0, 5, new Response(500)));

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5, 'timeout' => 30]), 'body');

    // One 30s attempt; a second would be 30 + 1 + 30 = 61s, over the budget.
    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('500')
        ->and(count($history))->toBe(1)
        ->and($this->pauses->getArrayCopy())->toBe([])
        ->and((int) $startedAt->diffInSeconds(Carbon::now()))->toBeLessThanOrEqual(WebhookTransport::DELIVERY_BUDGET_SECONDS);
});

test('a slow channel spends the tries that fit inside the budget and stops there', function () {
    Carbon::setTestNow(Carbon::now());
    $startedAt = Carbon::now();
    $history = chanFakeSlowWebhookEndpoint(array_fill(0, 5, new Response(500)));

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5, 'timeout' => 10]), 'body');

    // 10 + 1 + 10 + 3 + 10 + 3 + 10 = 47s; a fifth attempt would reach 60s.
    expect($result->success)->toBeFalse()
        ->and(count($history))->toBe(4)
        ->and($this->pauses->getArrayCopy())->toBe([1, 3, 3])
        ->and((int) $startedAt->diffInSeconds(Carbon::now()))->toBe(47)
        ->and((int) $startedAt->diffInSeconds(Carbon::now()))->toBeLessThanOrEqual(WebhookTransport::DELIVERY_BUDGET_SECONDS);
});

test('a fast-failing endpoint still gets every configured try', function () {
    Carbon::setTestNow(Carbon::now());
    $startedAt = Carbon::now();
    $history = chanFakeSlowWebhookEndpoint(array_fill(0, 5, new Response(500)));

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5, 'timeout' => 1]), 'body');

    // 5 x 1s of endpoint plus 1 + 3 + 3 + 3 of pauses = 15s, well inside the budget.
    expect(count($history))->toBe(5)
        ->and($this->pauses->getArrayCopy())->toBe([1, 3, 3, 3])
        ->and((int) $startedAt->diffInSeconds(Carbon::now()))->toBe(15);
});

test('a confirmation message is attempted exactly once however many tries the channel has', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5]), 'Your code', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect($result->success)->toBeFalse()
        ->and(count($history))->toBe(1)
        ->and($this->pauses->getArrayCopy())->toBe([]);
});

test('an alert on that same channel still spends every try', function () {
    $history = chanFakeWebhookEndpoint(array_fill(0, 5, new Response(500)));

    app(ChannelDispatcher::class)->send(chanWebhookChannel(['tries' => 5]), 'body', 'title', ['type' => 'alert', 'rule' => 'My rule']);

    expect(count($history))->toBe(5)->and($this->pauses->getArrayCopy())->toBe([1, 3, 3, 3]);
});

test('the send-time url guard still blocks a now-private target before any attempt', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = chanWebhookChannel(['tries' => 5]);
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse()
        ->and(count($history))->toBe(0)
        ->and($this->pauses->getArrayCopy())->toBe([]);
});

test('a signing channel with no secret fails without calling the endpoint', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create(['config' => ['url' => 'https://hooks.example.com/wx', 'sign' => true, 'secret' => null]]);

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse()
        ->and(count($history))->toBe(0);
});

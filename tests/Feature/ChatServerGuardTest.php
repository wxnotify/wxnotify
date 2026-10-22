<?php

use App\Models\NotificationChannel;
use App\Rules\SafeChatServerUrl;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\GoogleChatChannelType;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\PlainAsciiHost;
use App\Support\Delivery\Transports\MessagingTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

const CHAN_CHAT_PUBLIC_IP = '93.184.216.34';

/**
 * The guard's verdict on a URL, with the given fake DNS.
 *
 * @param  array<string, list<string>>  $dns
 */
function chanChatViolation(string $url, array $dns = ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]): ?string
{
    chanFakeChatDns($dns);

    return app(ChatServerGuard::class)->violation($url);
}

/**
 * A guard whose resolver records every name it is asked for.
 *
 * @return array{ChatServerGuard, ArrayObject<int, string>}
 */
function chanRecordingChatGuard(): array
{
    $lookups = new ArrayObject;

    return [new ChatServerGuard(function (string $host) use ($lookups): array {
        $lookups[] = $host;

        return [CHAN_CHAT_PUBLIC_IP];
    }), $lookups];
}

/**
 * Register a messaging type that names its own server (the Google Chat type with a server URL
 * and the given Apprise URL), so the transport's send-time check can be driven before Tasks 4-6
 * add the real ones. Returns a channel of that type.
 */
function chanOwnServerChatChannel(string $serverUrl, ?string $appriseUrl = null): NotificationChannel
{
    $appriseUrl ??= 'mmosts://'.parse_url($serverUrl, PHP_URL_HOST).'/abcdefghijklmnopqrstuvwxyz?redirect=no&cto=5&rto=10&image=no';
    $ownServer = new class($serverUrl, $appriseUrl) extends GoogleChatChannelType
    {
        public function __construct(private readonly string $server, private readonly string $apprise) {}

        public function slug(): string
        {
            return 'chan_guarded_chat';
        }

        public function serverUrl(array $config): ?string
        {
            return $this->server;
        }

        public function appriseUrl(array $config): string
        {
            return $this->apprise;
        }
    };
    app()->instance(ChannelTypeRegistry::class, new class($ownServer) extends ChannelTypeRegistry
    {
        public function __construct(private readonly ChannelType $ownServer) {}

        public function find(string $slug): ?ChannelType
        {
            return $slug === 'chan_guarded_chat' ? $this->ownServer : parent::find($slug);
        }
    });

    return NotificationChannel::factory()->googleChat()->create(['channel_type' => 'chan_guarded_chat']);
}

test('it allows a public https host on 443 and above 1023', function (string $url) {
    expect(chanChatViolation($url))->toBeNull();
})->with([
    'default port' => 'https://chat.example.com',
    'explicit 443' => 'https://chat.example.com:443/hooks/abc',
    '1024' => 'https://chat.example.com:1024',
    '8065 with a sub-path' => 'https://chat.example.com:8065/mm/hooks/abc',
    '65535' => 'https://chat.example.com:65535',
    'upper-case scheme and host' => 'HTTPS://Chat.Example.COM/',
    'trailing dot' => 'https://chat.example.com./',
    'public ipv4 literal' => 'https://93.184.216.34:8443',
    'public ipv6 literal' => 'https://[2606:4700::1]:8443/hooks/abc',
    'public mapped address in dotted form' => 'https://[::ffff:93.184.216.34]',
]);

test('it refuses private, loopback, link-local, metadata, cgnat and mapped addresses', function (string $address) {
    $literal = str_contains($address, ':') ? "[{$address}]" : $address;

    expect(chanChatViolation("https://{$literal}"))->toBe(ChatServerGuard::PRIVATE_NETWORK)
        ->and(chanChatViolation('https://chat.example.com', ['chat.example.com' => [$address]]))->toBe(ChatServerGuard::PRIVATE_NETWORK);
})->with([
    '10/8' => '10.0.0.5',
    '172.16/12' => '172.16.4.4',
    '192.168/16' => '192.168.1.10',
    'loopback' => '127.0.0.1',
    'loopback elsewhere in 127/8' => '127.1.2.3',
    'metadata' => '169.254.169.254',
    'cgnat' => '100.64.0.1',
    'this network' => '0.0.0.0',
    'reserved' => '240.0.0.1',
    'ipv6 loopback' => '::1',
    'ipv6 unspecified' => '::',
    'ipv6 link-local' => 'fe80::1',
    'ula fc00' => 'fc00::1',
    'ula fd00' => 'fd00::1234',
    'documentation range' => '2001:db8::1',
    'mapped loopback dotted' => '::ffff:127.0.0.1',
    'mapped loopback hex' => '::ffff:7f00:1',
    'mapped metadata hex' => '::ffff:a9fe:a9fe',
    'translated loopback' => '::ffff:0:7f00:1',
    'nat64 metadata' => '64:ff9b::a9fe:a9fe',
    '6to4 loopback' => '2002:7f00:1::',
    'site-local' => 'fec0::1',
    'benchmarking 198.18/15' => '198.19.0.1',
    'ietf special-purpose 192.0.0/24' => '192.0.0.8',
    'documentation range 3fff::/20' => '3fff::1',
]);

test('a name resolving to both a public and a private address is refused', function () {
    expect(chanChatViolation('https://chat.example.com', ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP, '10.0.0.5']]))->toBe(ChatServerGuard::PRIVATE_NETWORK)
        ->and(chanChatViolation('https://chat.example.com', ['chat.example.com' => ['2606:4700::1', '::1']]))->toBe(ChatServerGuard::PRIVATE_NETWORK);
});

test('it refuses single-label and blocked names before any lookup', function (string $url, string $message) {
    [$guard, $lookups] = chanRecordingChatGuard();

    expect($guard->violation($url))->toBe($message)
        ->and($lookups->getArrayCopy())->toBe([]);
})->with([
    'apprise' => ['https://apprise:8000', ChatServerGuard::NEEDS_DOMAIN],
    'pgsql' => ['https://pgsql', ChatServerGuard::NEEDS_DOMAIN],
    'localhost' => ['https://localhost', ChatServerGuard::NEEDS_DOMAIN],
    'localhost with a trailing dot' => ['https://localhost.', ChatServerGuard::NEEDS_DOMAIN],
    'internal' => ['https://internal', ChatServerGuard::NEEDS_DOMAIN],
    'decimal ip' => ['https://2130706433', ChatServerGuard::NEEDS_DOMAIN],
    'octal ip' => ['https://017700000001', ChatServerGuard::NEEDS_DOMAIN],
    'short dotted ip' => ['https://127.1', ChatServerGuard::NEEDS_DOMAIN],
    'hex dotted ip' => ['https://0x7f.1', ChatServerGuard::NEEDS_DOMAIN],
    'hex quad ip' => ['https://0x7f.0x0.0x0.0x1', ChatServerGuard::NEEDS_DOMAIN],
    'octal quad ip' => ['https://0177.0.0.01', ChatServerGuard::NEEDS_DOMAIN],
    'empty label' => ['https://chat..example.com', ChatServerGuard::NEEDS_DOMAIN],
    'bracketed ipv4' => ['https://[127.0.0.1]', ChatServerGuard::NEEDS_DOMAIN],
    'metadata name' => ['https://metadata.google.internal', ChatServerGuard::PRIVATE_NETWORK],
    'internal suffix' => ['https://db.internal', ChatServerGuard::PRIVATE_NETWORK],
    'internal suffix with a trailing dot' => ['https://db.internal.', ChatServerGuard::PRIVATE_NETWORK],
    'local suffix' => ['https://printer.local', ChatServerGuard::PRIVATE_NETWORK],
    'localhost suffix' => ['https://app.localhost', ChatServerGuard::PRIVATE_NETWORK],
    'lan suffix' => ['https://mm.home.lan', ChatServerGuard::PRIVATE_NETWORK],
]);

test('it refuses userinfo, a query, a fragment, http, ports below 1024 except 443, and port 0 or above 65535', function (string $url, string $message) {
    expect(chanChatViolation($url, ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP], 'evil.example.com' => ['10.0.0.5']]))->toBe($message);
})->with([
    'user and password' => ['https://user:pw@chat.example.com/hooks/abc', ChatServerGuard::NO_USERINFO],
    'user only' => ['https://user@chat.example.com', ChatServerGuard::NO_USERINFO],
    'good host as userinfo' => ['https://chat.example.com@10.0.0.5/hooks/abc', ChatServerGuard::NO_USERINFO],
    'empty userinfo' => ['https://@chat.example.com', ChatServerGuard::NO_USERINFO],
    'backslash before at' => ['https://evil.example.com\\@chat.example.com', ChatServerGuard::NEEDS_URL],
    'backslash as a path' => ['https://chat.example.com\\hooks', ChatServerGuard::NEEDS_URL],
    'hash before at' => ['https://chat.example.com#@evil.example.com', ChatServerGuard::NO_QUERY],
    'question mark before at' => ['https://chat.example.com?@evil.example.com', ChatServerGuard::NO_QUERY],
    'query' => ['https://chat.example.com/hooks/abc?x=1', ChatServerGuard::NO_QUERY],
    'empty query' => ['https://chat.example.com/?', ChatServerGuard::NO_QUERY],
    'fragment' => ['https://chat.example.com/#top', ChatServerGuard::NO_QUERY],
    'http' => ['http://chat.example.com', ChatServerGuard::HTTPS_ONLY],
    'ftp' => ['ftp://chat.example.com', ChatServerGuard::HTTPS_ONLY],
    'no scheme' => ['chat.example.com', ChatServerGuard::NEEDS_URL],
    'scheme-relative' => ['//chat.example.com', ChatServerGuard::NEEDS_URL],
    'no host' => ['https:///hooks', ChatServerGuard::NEEDS_URL],
    'blank' => ['', ChatServerGuard::NEEDS_URL],
    'space in the url' => ['https://chat.example.com /hooks', ChatServerGuard::NEEDS_URL],
    'tab in the host' => ["https://chat.example\t.com", ChatServerGuard::NEEDS_URL],
    'newline' => ["https://chat.example.com\n/hooks", ChatServerGuard::NEEDS_URL],
    'port 80' => ['https://chat.example.com:80', ChatServerGuard::BAD_PORT],
    'port 22' => ['https://chat.example.com:22', ChatServerGuard::BAD_PORT],
    'port 1023' => ['https://chat.example.com:1023', ChatServerGuard::BAD_PORT],
    'port 0' => ['https://chat.example.com:0', ChatServerGuard::BAD_PORT],
    'port 65536' => ['https://chat.example.com:65536', ChatServerGuard::BAD_PORT],
    'port 99999' => ['https://chat.example.com:99999', ChatServerGuard::BAD_PORT],
    'empty port' => ['https://chat.example.com:/hooks', ChatServerGuard::BAD_PORT],
]);

test('it refuses a host that does not resolve', function () {
    expect(chanChatViolation('https://nowhere.example.com'))->toBe(ChatServerGuard::NOT_FOUND)
        ->and(chanChatViolation('https://chat.example.com', []))->toBe(ChatServerGuard::NOT_FOUND);
});

test('it refuses non-ascii and percent-encoded hosts', function (string $url) {
    [$guard, $lookups] = chanRecordingChatGuard();

    expect($guard->violation($url))->toBe(PlainAsciiHost::MESSAGE)
        ->and($lookups->getArrayCopy())->toBe([]);
})->with([
    'fullwidth dots' => 'https://127。0。0。1',
    'cyrillic lookalike' => 'https://сhat.example.com',
    'accented' => 'https://chät.example.com',
    'percent-encoded' => 'https://chat%2Eexample.com',
    'percent-encoded loopback' => 'https://%31%32%37.0.0.1',
    'ipv6 zone id' => 'https://[fe80::1%25eth0]',
]);

test('a punycode host is judged by the name as written', function () {
    expect(chanChatViolation('https://xn--80ak6aa92e.com', ['xn--80ak6aa92e.com' => [CHAN_CHAT_PUBLIC_IP]]))->toBeNull()
        ->and(chanChatViolation('https://xn--80ak6aa92e.com', ['xn--80ak6aa92e.com' => ['10.0.0.5']]))->toBe(ChatServerGuard::PRIVATE_NETWORK);
});

test('self-host with private networks allowed accepts a lan host and http on 80 or above 1023', function () {
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);
    $lan = ['mm.home.lan' => ['192.168.1.20'], 'chat.example.com' => ['10.0.0.5']];

    expect(app(ChatServerGuard::class)->privateNetworksAllowed())->toBeTrue()
        ->and(chanChatViolation('https://mm.home.lan', $lan))->toBeNull()
        ->and(chanChatViolation('https://chat.example.com:8065', $lan))->toBeNull()
        ->and(chanChatViolation('https://192.168.1.20', $lan))->toBeNull()
        ->and(chanChatViolation('http://mm.home.lan', $lan))->toBeNull()
        ->and(chanChatViolation('http://mm.home.lan:80', $lan))->toBeNull()
        ->and(chanChatViolation('http://mm.home.lan:8065', $lan))->toBeNull()
        ->and(chanChatViolation('http://mm.home.lan:443', $lan))->toBe(ChatServerGuard::BAD_PORT)
        ->and(chanChatViolation('http://mm.home.lan:8', $lan))->toBe(ChatServerGuard::BAD_PORT)
        ->and(chanChatViolation('https://mm.home.lan:80', $lan))->toBe(ChatServerGuard::BAD_PORT)
        ->and(chanChatViolation('https://mattermost:8065', $lan))->toBe(ChatServerGuard::NEEDS_DOMAIN)
        ->and(chanChatViolation('https://user@mm.home.lan', $lan))->toBe(ChatServerGuard::NO_USERINFO)
        ->and(chanChatViolation('https://gone.home.lan', $lan))->toBe(ChatServerGuard::NOT_FOUND)
        ->and(chanChatViolation('ftp://mm.home.lan', $lan))->toBe(ChatServerGuard::HTTPS_ONLY);
});

test('the cloud ignores the private-network flag', function (string $mode) {
    config(['wxnotify.mode' => $mode, 'wxnotify.webhooks.allow_private_networks' => true]);

    expect(app(ChatServerGuard::class)->privateNetworksAllowed())->toBeFalse()
        ->and(chanChatViolation('https://chat.example.com', ['chat.example.com' => ['10.0.0.5']]))->toBe(ChatServerGuard::PRIVATE_NETWORK)
        ->and(chanChatViolation('http://chat.example.com'))->toBe(ChatServerGuard::HTTPS_ONLY);

    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => false]);

    expect(app(ChatServerGuard::class)->privateNetworksAllowed())->toBeFalse()
        ->and(chanChatViolation('https://chat.example.com', ['chat.example.com' => ['10.0.0.5']]))->toBe(ChatServerGuard::PRIVATE_NETWORK)
        ->and(chanChatViolation('http://chat.example.com'))->toBe(ChatServerGuard::HTTPS_ONLY);
})->with(['cloud', 'an unknown mode' => 'SelfHost']);

test('no message repeats any part of the url', function (string $url) {
    $message = chanChatViolation($url, ['secret-host.example.com' => ['10.0.0.5']]);

    expect($message)->not->toBeNull()
        ->and($message)->toBeIn([
            ChatServerGuard::NEEDS_URL, ChatServerGuard::HTTPS_ONLY, ChatServerGuard::NO_USERINFO,
            ChatServerGuard::NO_QUERY, ChatServerGuard::NEEDS_DOMAIN, ChatServerGuard::BAD_PORT,
            ChatServerGuard::NOT_FOUND, ChatServerGuard::PRIVATE_NETWORK, PlainAsciiHost::MESSAGE,
        ])
        ->and($message)->not->toContain('secret')
        ->and($message)->not->toContain('9999')
        ->and($message)->not->toContain('tok3n');
})->with([
    'private' => 'https://secret-host.example.com/hooks/tok3n',
    'userinfo' => 'https://secret:tok3n@secret-host.example.com',
    'port' => 'https://secret-host.example.com:999/hooks/tok3n',
    'query' => 'https://secret-host.example.com/?tok3n=secret',
    'not found' => 'https://secret-nowhere.example.com/tok3n',
    'http' => 'http://secret-host.example.com/tok3n',
    'non-ascii' => 'https://sécret.example.com/tok3n',
    'single label' => 'https://secret:9999/tok3n',
]);

test('the rule fails with the guard\'s message', function () {
    chanFakeChatDns(['chat.example.com' => ['10.0.0.5'], 'ok.example.com' => [CHAN_CHAT_PUBLIC_IP]]);

    $refused = Validator::make(['server' => 'https://chat.example.com'], ['server' => [new SafeChatServerUrl]]);
    $allowed = Validator::make(['server' => 'https://ok.example.com'], ['server' => [new SafeChatServerUrl]]);
    $notAString = Validator::make(['server' => ['https://ok.example.com']], ['server' => [new SafeChatServerUrl]]);

    expect($refused->errors()->first('server'))->toBe(ChatServerGuard::PRIVATE_NETWORK)
        ->and($allowed->passes())->toBeTrue()
        ->and($notAString->errors()->first('server'))->toBe(ChatServerGuard::NEEDS_URL);
});

test('a send to a server that now resolves privately is refused before any http', function () {
    Http::fake();
    $channel = chanOwnServerChatChannel('https://chat.example.com');
    chanFakeChatDns(['chat.example.com' => ['169.254.169.254']]);

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->error)->toBe(ChatServerGuard::PRIVATE_NETWORK);
    Http::assertNothingSent();
});

test('a send to a server that no longer resolves is refused before any http', function () {
    Http::fake();
    $channel = chanOwnServerChatChannel('https://chat.example.com');

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->error)->toBe(ChatServerGuard::NOT_FOUND);
    Http::assertNothingSent();
});

test('a send to a public server reaches apprise with redirects off and the timeouts set', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = chanOwnServerChatChannel('https://chat.example.com');
    chanFakeChatDns(['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]);

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'mmosts://chat.example.com/abcdefghijklmnopqrstuvwxyz?redirect=no&cto=5&rto=10&image=no');
});

test('a send whose apprise url names another host than the guarded server is refused before any http', function (string $appriseUrl) {
    Http::fake();
    $channel = chanOwnServerChatChannel('https://chat.example.com', $appriseUrl);
    chanFakeChatDns(['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]);

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->error)->toBe(MessagingTransport::SERVER_MISMATCH);
    Http::assertNothingSent();
})->with([
    'private address' => 'mmosts://10.0.0.5/abcdefghijklmnopqrstuvwxyz?redirect=no',
    'another name' => 'mmosts://evil.example.com/abcdefghijklmnopqrstuvwxyz',
    'userinfo hides the guarded host' => 'rockets://chat.example.com@10.0.0.5:8443?redirect=no',
    'encoded host' => 'mmosts://chat%2Eexample.com/abcdefghijklmnopqrstuvwxyz',
    'no host' => 'mmosts:///abcdefghijklmnopqrstuvwxyz',
    'ipv6 private' => 'matrixs://tok@[::1]/%21r%3Aex.org',
    'not a url' => 'nonsense',
]);

test('the mattermost, rocket.chat and matrix url shapes on the guarded host are sent', function (string $serverUrl, string $appriseUrl, array $dns) {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = chanOwnServerChatChannel($serverUrl, $appriseUrl);
    chanFakeChatDns($dns);

    expect(app(MessagingTransport::class)->send($channel, 'body', null)->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === $appriseUrl);
})->with([
    'mattermost' => ['https://Chat.Example.com:8065/mm', 'mmosts://chat.example.com:8065/mm/abcdefghijklmnopqrstuvwxyz?redirect=no&cto=5&rto=10', ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]],
    'rocket.chat' => ['https://chat.example.com', 'rockets://id%2Ftok%40en@chat.example.com?redirect=no&cto=5&rto=10', ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]],
    'matrix ipv6' => ['https://[2606:4700:0::1]:8448', 'matrixs://tok%40x@[2606:4700::1]:8448/%21r%3Aex.org?redirect=no&cto=5&rto=10&discovery=no', []],
    'trailing dot' => ['https://chat.example.com.', 'mmosts://chat.example.com/abcdefghijklmnopqrstuvwxyz', ['chat.example.com' => [CHAN_CHAT_PUBLIC_IP]]],
]);

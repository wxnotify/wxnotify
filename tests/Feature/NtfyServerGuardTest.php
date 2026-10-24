<?php

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\Transports\AppriseTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * Whether the ntfy "server" field accepts the value, by the dashboard form's rules and by the
 * API/app descriptors' rules, and the dashboard's errors.
 *
 * @return array{form: bool, app: bool, errors: list<string>}
 */
function ntfyServerValidate(string $server): array
{
    $type = app(ChannelTypeRegistry::class)->find('ntfy');
    $field = collect($type->formSchema())->keyBy(fn ($component) => $component->getName())['server'];
    $form = Validator::make(['server' => $server], ['server' => $field->getValidationRules()]);
    $descriptor = collect($type->appSetupFields(User::factory()->make()))->firstWhere('key', 'server');
    $app = Validator::make(['server' => $server], ['server' => ['nullable', 'string', ...$descriptor->rules]]);

    return ['form' => $form->passes(), 'app' => $app->passes(), 'errors' => $form->errors()->get('server')];
}

function ntfySend(string $appriseUrl): array
{
    $channel = NotificationChannel::factory()->create(['channel_type' => 'ntfy', 'apprise_url' => $appriseUrl]);

    return [$channel, app(AppriseTransport::class)->send($channel, 'body', 'title')];
}

test('the server field accepts the public ntfy.sh and a public https host', function () {
    chanFakeChatDns(['ntfy.sh' => ['93.184.216.34'], 'ntfy.example.com' => ['93.184.216.34']]);

    foreach (['https://ntfy.sh', 'https://ntfy.example.com', 'https://ntfy.example.com:8443/'] as $server) {
        expect(ntfyServerValidate($server))->toMatchArray(['form' => true, 'app' => true]);
    }
});

test('the server field refuses loopback, private, metadata and unresolvable hosts with a fixed message', function (string $server, string $message) {
    chanFakeChatDns(['internal.example.com' => ['10.0.0.5']]);

    $result = ntfyServerValidate($server);

    expect($result['form'])->toBeFalse()
        ->and($result['app'])->toBeFalse()
        ->and($result['errors'])->toBe([$message]);
})->with([
    'loopback literal' => ['https://127.0.0.1', ChatServerGuard::PRIVATE_NETWORK],
    'metadata' => ['https://169.254.169.254', ChatServerGuard::PRIVATE_NETWORK],
    'name resolving private' => ['https://internal.example.com', ChatServerGuard::PRIVATE_NETWORK],
    'localhost' => ['https://localhost', ChatServerGuard::NEEDS_DOMAIN],
    'unknown name' => ['https://nope.example.com', ChatServerGuard::NOT_FOUND],
    'plain http' => ['http://ntfy.example.com', ChatServerGuard::HTTPS_ONLY],
]);

test('private targets and http are allowed only when self-host allows private networks', function () {
    chanFakeChatDns(['ntfy.lan.example.com' => ['192.168.1.20']]);

    expect(ntfyServerValidate('http://ntfy.lan.example.com')['form'])->toBeFalse();

    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);

    expect(ntfyServerValidate('http://ntfy.lan.example.com'))->toMatchArray(['form' => true, 'app' => true])
        ->and(ntfyServerValidate('https://127.0.0.1:8080')['form'])->toBeTrue();
});

test('the default server and a public https server still send through Apprise', function (string $appriseUrl) {
    chanFakeChatDns(['ntfy.example.com' => ['93.184.216.34']]);
    Http::fake(['*' => Http::response('', 200)]);

    [, $result] = ntfySend($appriseUrl);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === $appriseUrl);
})->with([
    'default server' => 'ntfy://wx-topic',
    'public https server' => 'ntfys://ntfy.example.com/wx-topic',
]);

test('a saved ntfy channel whose server is now refused fails at send with a fixed message and is kept', function (string $appriseUrl) {
    chanFakeChatDns(['internal.example.com' => ['10.0.0.5'], 'ntfy.example.com' => ['93.184.216.34']]);
    Http::fake();

    [$channel, $result] = ntfySend($appriseUrl);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(NtfyChannelType::SERVER_REFUSED)
        ->and($result->error)->not->toContain('internal')
        ->and($channel->fresh())->not->toBeNull();
    Http::assertNothingSent();
})->with([
    'resolves private now' => 'ntfys://internal.example.com/wx-topic',
    'loopback literal' => 'ntfys://127.0.0.1:8443/wx-topic',
    'plain http' => 'ntfy://ntfy.example.com/wx-topic',
    'userinfo' => 'ntfys://user:pw@ntfy.example.com/wx-topic',
]);

test('a stored private or http server sends when self-host allows private networks', function () {
    chanFakeChatDns(['ntfy.lan.example.com' => ['192.168.1.20']]);
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);
    Http::fake(['*' => Http::response('', 200)]);

    [, $result] = ntfySend('ntfy://ntfy.lan.example.com/wx-topic');

    expect($result->success)->toBeTrue();
});

test('a server typed with any spelling saves as the normalised URL and sends', function (string $server, string $expected) {
    chanFakeChatDns(['ntfy.sh' => ['93.184.216.34'], 'ntfy.example.com' => ['93.184.216.34']]);
    Http::fake(['*' => Http::response('', 200)]);

    expect(ntfyServerValidate($server))->toMatchArray(['form' => true, 'app' => true]);

    $appriseUrl = app(ChannelTypeRegistry::class)->find('ntfy')->buildAppriseUrl(['topic' => 'wx-topic', 'server' => $server]);
    [, $result] = ntfySend($appriseUrl);

    expect($appriseUrl)->toBe($expected)
        ->and($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === $expected);
})->with([
    'uppercase scheme' => ['HTTPS://ntfy.example.com', 'ntfys://ntfy.example.com/wx-topic'],
    'uppercase host' => ['https://NTFY.Example.COM', 'ntfys://ntfy.example.com/wx-topic'],
    'trailing dot' => ['https://ntfy.example.com.', 'ntfys://ntfy.example.com/wx-topic'],
    'explicit default port' => ['https://ntfy.example.com:443', 'ntfys://ntfy.example.com/wx-topic'],
    'non-default port and slash' => ['https://ntfy.example.com:8443/', 'ntfys://ntfy.example.com:8443/wx-topic'],
    'ntfy.sh' => ['https://ntfy.sh', 'ntfys://ntfy.sh/wx-topic'],
    'ntfy.sh explicit port' => ['https://ntfy.sh:443', 'ntfys://ntfy.sh/wx-topic'],
    'uppercase scheme with a path' => ['HTTPS://ntfy.example.com/extra', 'ntfys://ntfy.example.com/extra/wx-topic'],
]);

test('a blank server still stores the default ntfy URL', function () {
    expect(app(ChannelTypeRegistry::class)->find('ntfy')->buildAppriseUrl(['topic' => 'wx-topic', 'server' => '']))->toBe('ntfy://wx-topic');
});

test('the refusal text tells the subscriber to remove and re-add the channel', function () {
    expect(NtfyChannelType::SERVER_REFUSED)->toContain('Remove this channel and add it again')->not->toContain('Edit');
});

test('at send time metadata, localhost and unresolvable servers fail before any HTTP', function (string $appriseUrl, string $message) {
    chanFakeChatDns([]);
    Http::fake();

    [, $result] = ntfySend($appriseUrl);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe($message);
    Http::assertNothingSent();
})->with([
    'metadata' => ['ntfys://169.254.169.254/wx-topic', NtfyChannelType::SERVER_REFUSED],
    'localhost' => ['ntfys://localhost/wx-topic', NtfyChannelType::SERVER_REFUSED],
    'unresolvable name' => ['ntfys://nope.example.com/wx-topic', NtfyChannelType::SERVER_UNRESOLVED],
]);

test('a name lookup failure for a public host is not reported as a policy refusal', function () {
    chanFakeChatDns([]);
    Http::fake();

    [, $result] = ntfySend('ntfys://ntfy.sh/wx-topic');

    expect($result->error)->toBe(NtfyChannelType::SERVER_UNRESOLVED)
        ->and(NtfyChannelType::SERVER_UNRESOLVED)->not->toContain('not allowed');
    Http::assertNothingSent();
});

test('a non-ntfy Apprise channel is sent without any server lookup', function () {
    app()->instance(ChatServerGuard::class, new ChatServerGuard(function (string $host): array {
        throw new RuntimeException('no lookup expected');
    }));
    Http::fake(['*' => Http::response('', 200)]);

    [, $result] = ntfySend('discord://123456/token');

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'discord://123456/token');
});

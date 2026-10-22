<?php

use App\Rules\SafeWebhookUrl;
use App\Support\Delivery\WebhookUrlGuard;
use Illuminate\Support\Facades\Validator;

function chanGuard(array $ips = ['93.184.216.34']): WebhookUrlGuard
{
    return new WebhookUrlGuard(fn (string $host): array => $ips);
}

test('a public https url on a normal port is allowed', function () {
    expect(chanGuard()->violation('https://hooks.example.com/wx'))->toBeNull()
        ->and(chanGuard()->violation('https://hooks.example.com:8443/wx'))->toBeNull();
});

test('non-https, credentials, bad ports and missing hosts are rejected', function (string $url) {
    expect(chanGuard()->violation($url))->not->toBeNull();
})->with([
    'http' => 'http://hooks.example.com/wx',
    'ftp' => 'ftp://hooks.example.com/wx',
    'userinfo' => 'https://user:pass@hooks.example.com/wx',
    'ssh port' => 'https://hooks.example.com:22/wx',
    'smtp port' => 'https://hooks.example.com:25/wx',
    'no host' => 'https:///wx',
    'not a url' => 'nonsense',
]);

test('private, loopback, link-local, metadata, cgnat and reserved addresses are rejected', function (string $ip) {
    expect(chanGuard([$ip])->violation('https://hooks.example.com/wx'))->not->toBeNull();
})->with(['10.0.0.5', '172.16.4.4', '192.168.1.10', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '240.0.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', 'fec0::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '::ffff:0:7f00:1', '::ffff:0:a00:5', '2001:db8::1']);

test('a public ipv4-mapped address in dotted form is still allowed', function () {
    // The hex IPv4-translated block (::ffff:0:0:0/96) is blocked outright, but
    // the dotted IPv4-mapped form (::ffff:a.b.c.d) is still judged by its
    // embedded IPv4 address, so a public one stays usable.
    expect(chanGuard(['::ffff:93.184.216.34'])->violation('https://hooks.example.com/wx'))->toBeNull();
});

test('ip literals in the url are checked without dns', function () {
    expect(chanGuard()->violation('https://10.0.0.5/wx'))->not->toBeNull()
        ->and(chanGuard()->violation('https://[::1]/wx'))->not->toBeNull()
        ->and(chanGuard()->violation('https://93.184.216.34/wx'))->toBeNull();
});

test('a hostname that resolves to any private address is rejected even when another address is public', function () {
    expect(chanGuard(['93.184.216.34', '10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();
});

test('an unresolvable host is rejected', function () {
    expect(chanGuard([])->violation('https://nope.example.com/wx'))->not->toBeNull();
});

test('internal hostnames are rejected without resolving', function (string $host) {
    expect(chanGuard()->violation("https://{$host}/wx"))->not->toBeNull();
})->with(['localhost', 'printer.local', 'db.internal', 'metadata.google.internal']);

test('private networks are allowed only for self-hosted instances that opt in', function () {
    config(['wxnotify.webhooks.allow_private_networks' => true, 'wxnotify.mode' => 'cloud']);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();

    config(['wxnotify.mode' => 'selfhost']);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->toBeNull()
        ->and(chanGuard()->violation('http://hooks.example.com/wx'))->not->toBeNull();

    config(['wxnotify.webhooks.allow_private_networks' => false]);
    expect(chanGuard(['10.0.0.5'])->violation('https://hooks.example.com/wx'))->not->toBeNull();
});

test('the validation rule reports the guard message', function () {
    app()->instance(WebhookUrlGuard::class, chanGuard(['10.0.0.5']));

    $validator = Validator::make(['url' => 'https://hooks.example.com/wx'], ['url' => [new SafeWebhookUrl]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('url'))->toContain('private');
});

test('an over-long host name is rejected instead of throwing from the resolver', function () {
    $host = implode('.', array_fill(0, 30, str_repeat('a', 10))).'.example.com';

    expect((new WebhookUrlGuard)->violation("https://{$host}/wx"))->toBeString();
});

test('hosts curl would rewrite are refused with a fixed message, before DNS and even when private networks are allowed', function (string $mode, string $host) {
    config(['wxnotify.mode' => $mode, 'wxnotify.webhooks.allow_private_networks' => true]);
    $message = 'Enter the server host name in plain ASCII (use the xn-- form for international names).';

    expect(chanGuard()->violation("https://{$host}/wx"))->toBe($message)
        ->and(chanGuard()->proxyViolation("http://{$host}:3128"))->toBe($message);
})->with(['cloud', 'selfhost'])->with([
    'unicode letter' => 'ä-secret.example.com',
    'fullwidth dots' => '127。0。0。1',
    'percent-encoded digits' => '%31%32%37.0.0.1',
    'percent-encoded name' => '%6c%6fcalhost',
    'space' => 'exa mple.com',
]);

test('punycode, underscore and IPv6 hosts still pass the host-syntax check', function (string $host) {
    expect(chanGuard()->violation("https://{$host}/wx"))->toBeNull()
        ->and(chanGuard()->proxyViolation("http://{$host}:3128"))->toBeNull();
})->with([
    'punycode' => 'xn--4ca.example.com',
    'underscore' => 'my_hooks.example.com',
    'public ipv6' => '[2606:4700::1111]',
]);

test('the host-syntax refusal never echoes the host', function () {
    expect(chanGuard()->violation('https://ä-secret.example.com/wx'))->not->toContain('secret');
});

test('requestOptions pins the host to the addresses it validated, on the URL\'s port', function (string $url, string $expected) {
    $options = chanGuard(['93.184.216.34', '2606:4700::1111'])->requestOptions($url);

    expect($options)->toBe(['curl' => [CURLOPT_RESOLVE => [$expected]]]);
})->with([
    'default port' => ['https://Hooks.Example.com/wx', 'hooks.example.com:443:93.184.216.34,[2606:4700::1111]'],
    'explicit port' => ['https://hooks.example.com:8443/wx', 'hooks.example.com:8443:93.184.216.34,[2606:4700::1111]'],
]);

test('requestOptions looks the host up once and pins exactly that answer', function () {
    $answers = [['93.184.216.34'], ['10.0.0.5']];
    $guard = new WebhookUrlGuard(function (string $host) use (&$answers): array {
        return array_shift($answers) ?? [];
    });

    expect($guard->requestOptions('https://hooks.example.com/wx'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['hooks.example.com:443:93.184.216.34']]])
        ->and($answers)->toHaveCount(1);
});

test('requestOptions needs no pin for an IP-literal host', function () {
    expect(chanGuard()->requestOptions('https://93.184.216.34/wx'))->toBe([])
        ->and(chanGuard()->requestOptions('https://[2606:4700::1111]/wx'))->toBe([]);
});

test('requestOptions refuses what violation refuses, an unresolved host, and any private answer', function (string $url, array $ips) {
    expect(chanGuard($ips)->requestOptions($url))->toBeNull();
})->with([
    'private answer among public' => ['https://hooks.example.com/wx', ['93.184.216.34', '10.0.0.5']],
    'unresolved' => ['https://hooks.example.com/wx', []],
    'private literal' => ['https://10.0.0.5/wx', []],
    'internal name' => ['https://db.internal/wx', ['93.184.216.34']],
    'http' => ['http://hooks.example.com/wx', ['93.184.216.34']],
    'non-ASCII host' => ['https://ä.example.com/wx', ['93.184.216.34']],
]);

test('blocked host names are refused without any DNS lookup', function () {
    $lookups = 0;
    $guard = new WebhookUrlGuard(function (string $host) use (&$lookups): array {
        $lookups++;

        return ['93.184.216.34'];
    });

    expect($guard->requestOptions('https://printer.local/wx'))->toBeNull()
        ->and($guard->violation('https://localhost/wx'))->toBeString()
        ->and($lookups)->toBe(0);
});

test('a self-hosted instance that allows private networks pins the private answer, and still needs the name to resolve', function () {
    config(['wxnotify.webhooks.allow_private_networks' => true, 'wxnotify.mode' => 'selfhost']);

    expect(chanGuard(['10.0.0.5'])->requestOptions('https://hooks.example.com/wx'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['hooks.example.com:443:10.0.0.5']]])
        ->and(chanGuard([])->requestOptions('https://hooks.example.com/wx'))->toBeNull();
});

test('proxyRequestOptions pins the proxy host on its port, and on curl\'s default ports when none is given', function (string $url, array $entries) {
    expect(chanGuard()->proxyRequestOptions($url))->toBe(['curl' => [CURLOPT_RESOLVE => $entries]]);
})->with([
    'explicit port' => ['http://user:pa%3Ass@proxy.example.com:3128', ['proxy.example.com:3128:93.184.216.34']],
    'http, no port' => ['http://proxy.example.com', ['proxy.example.com:1080:93.184.216.34', 'proxy.example.com:80:93.184.216.34']],
    'https, no port' => ['https://proxy.example.com', ['proxy.example.com:1080:93.184.216.34', 'proxy.example.com:443:93.184.216.34']],
]);

test('proxyRequestOptions refuses a proxy that proxyViolation refuses or that does not resolve', function () {
    expect(chanGuard(['10.0.0.5'])->proxyRequestOptions('http://proxy.example.com:3128'))->toBeNull()
        ->and(chanGuard([])->proxyRequestOptions('http://proxy.example.com:3128'))->toBeNull()
        ->and(chanGuard()->proxyRequestOptions('http://proxy.example.com:22'))->toBeNull();
});

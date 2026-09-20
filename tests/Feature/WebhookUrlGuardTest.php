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
})->with(['10.0.0.5', '172.16.4.4', '192.168.1.10', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '240.0.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', 'fec0::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '::ffff:0:7f00:1', '::ffff:0:a00:5']);

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

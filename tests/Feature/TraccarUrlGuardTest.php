<?php

use App\Rules\SafeTraccarUrl;
use App\Support\TraccarUrlGuard;
use Illuminate\Support\Facades\Validator;

function traccarGuard(array $ips = ['93.184.216.34']): TraccarUrlGuard
{
    return new TraccarUrlGuard(fn (string $host): array => $ips);
}

test('cloud allows a public host over http and https, including the default Traccar port', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))->toBeNull();
})->with([
    'https' => 'https://traccar.example.com',
    'http' => 'http://traccar.example.com',
    'default port' => 'http://traccar.example.com:8082',
    'path' => 'https://traccar.example.com/traccar',
]);

test('cloud blocks loopback, private, metadata and reserved targets', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))->toBeString();
})->with([
    'localhost' => 'http://localhost:8082',
    '127.0.0.1' => 'http://127.0.0.1:8082',
    '10.x' => 'http://10.0.0.5:8082',
    '172.16.x' => 'http://172.16.4.4',
    '192.168.x' => 'http://192.168.1.50:8082',
    'metadata ip' => 'http://169.254.169.254/latest',
    'ipv6 loopback' => 'http://[::1]:8082',
    'ipv4-mapped private' => 'http://[::ffff:10.0.0.1]:8082',
    '.internal name' => 'http://traccar.internal',
    '.lan name' => 'http://traccar.lan',
    '.local name' => 'http://traccar.local',
    'decimal shorthand' => 'http://2130706433',
    'hex shorthand' => 'http://0x7f.1',
    'short dotted shorthand' => 'http://127.1',
    'octal shorthand' => 'http://0177.0.0.1',
    'trailing-dot localhost' => 'http://localhost.',
    'uppercase localhost' => 'http://LOCALHOST',
]);

test('cloud rejects a numeric-shorthand host with a fixed message that does not echo it', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))
        ->toBe('The URL host must be a name or a standard IP address, not a numeric shorthand.');
})->with([
    'decimal' => 'http://2130706433',
    'hex' => 'http://0x7f.1',
    'short dotted' => 'http://127.1',
    'octal' => 'http://0177.0.0.1',
]);

test('cloud blocks a host name that resolves to a private address, and one that does not resolve', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard(['10.0.0.5'])->violation('https://traccar.example.com'))->toBeString()
        ->and(traccarGuard([])->violation('https://traccar.example.com'))->toBeString();
});

test('both modes reject credentials, non-http schemes and malformed URLs', function (string $mode, string $url) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard()->violation($url))->toBeString();
})->with(['cloud', 'selfhost'])->with([
    'userinfo' => 'https://user:pass@traccar.example.com',
    'ftp' => 'ftp://traccar.example.com',
    'file' => 'file:///etc/passwd',
    'no host' => 'https:///api',
    'not a url' => 'nonsense',
]);

test('selfhost allows private and LAN targets', function (string $url) {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard(['10.0.0.5'])->violation($url))->toBeNull();
})->with([
    'private ip' => 'http://192.168.1.50:8082',
    'localhost' => 'http://localhost:8082',
    'docker name' => 'http://traccar:8082',
    'private-resolving host' => 'https://traccar.home.example.com',
]);

test('selfhost still blocks metadata and link-local targets', function (string $url) {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard()->violation($url))->toBeString();
})->with([
    'metadata ip' => 'http://169.254.169.254/latest',
    'link-local' => 'http://169.254.10.10',
    'google metadata' => 'http://metadata.google.internal',
    'ipv6 link-local' => 'http://[fe80::1]',
]);

test('an unknown or missing mode is treated as cloud', function () {
    config(['wxnotify.mode' => 'staging']);
    expect(traccarGuard()->violation('http://192.168.1.50:8082'))->toBeString();

    config(['wxnotify.mode' => null]);
    expect(traccarGuard()->violation('http://192.168.1.50:8082'))->toBeString();
});

test('no violation message repeats the URL or host', function () {
    config(['wxnotify.mode' => 'cloud']);

    foreach (['http://secret-host.example.com:8082', 'http://10.99.88.77:8082', 'https://u:p@secret-host.example.com'] as $url) {
        $message = traccarGuard(['10.99.88.77'])->violation($url);

        expect($message)->toBeString()
            ->not->toContain('secret-host')
            ->not->toContain('10.99.88.77');
    }
});

test('the default container binding is stubbed so fake hosts never hit DNS', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(app(TraccarUrlGuard::class)->violation('https://traccar.example.com'))->toBeNull();
});

test('the validation rule reports the guard message', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, traccarGuard(['10.0.0.5']));

    $validator = Validator::make(['url' => 'https://traccar.example.com'], ['url' => [new SafeTraccarUrl]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('url'))->toContain('private');
});

test('the validation rule passes a safe URL', function () {
    config(['wxnotify.mode' => 'cloud']);

    $validator = Validator::make(['url' => 'https://traccar.example.com'], ['url' => [new SafeTraccarUrl]]);

    expect($validator->passes())->toBeTrue();
});

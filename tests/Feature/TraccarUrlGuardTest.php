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

test('both modes refuse non-ASCII or percent-encoded hosts and query strings even when DNS looks public', function (string $mode, string $url) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard()->violation($url))->toBeString();
})->with(['cloud', 'selfhost'])->with([
    'unicode host' => 'http://ä.example.com',
    'percent-encoded letter' => 'http://%61.example.com',
    'percent-encoded localhost' => 'http://%6c%6fcalhost:8082',
    'ipv6 zone id' => 'http://[fe80::1%25eth0]',
    'space in host' => 'http://exa mple.com',
    'ideographic full stop' => 'http://example。com',
    'query string' => 'https://traccar.example.com/?x=1',
    'fragment' => 'https://traccar.example.com/#frag',
    'bare question mark' => 'https://traccar.example.com?',
    'bare hash' => 'https://traccar.example.com/#',
]);

test('both modes give fixed messages for non-ASCII hosts and query strings that never echo the host', function (string $mode) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard()->violation('http://ä-secret.example.com'))
        ->toBe('Enter the server host name in plain ASCII (use the xn-- form for international names).')
        ->and(traccarGuard()->violation('https://secret-host.example.com/?token=abc'))
        ->toBe('The URL must not contain a query string or fragment.');
})->with(['cloud', 'selfhost']);

test('cloud still allows punycode names, underscores, public IPv4 and bracketed public IPv6 hosts', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))->toBeNull();
})->with([
    'punycode' => 'http://xn--4ca.example.com',
    'hyphen, underscore, digit and port' => 'https://my-traccar_1.example.com:8082',
    'public ipv4' => 'http://93.184.216.34:8082',
    'public ipv6' => 'http://[2606:4700::1111]:8082',
]);

test('selfhost still allows ordinary host syntax', function (string $url) {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard()->violation($url))->toBeNull();
})->with([
    'punycode' => 'http://xn--4ca.example.com',
    'hyphen, underscore, digit and port' => 'https://my-traccar_1.example.com:8082',
    'public ipv4' => 'http://93.184.216.34:8082',
    'public ipv6' => 'http://[2606:4700::1111]:8082',
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

test('requestOptions pins the host to the addresses it validated, on the URL\'s port', function (string $url, string $expected) {
    config(['wxnotify.mode' => 'cloud']);

    $options = traccarGuard(['93.184.216.34', '2606:4700::1111'])->requestOptions($url);

    expect($options)->toBe(['curl' => [CURLOPT_RESOLVE => [$expected]]]);
})->with([
    'https default port' => ['https://traccar.example.com', 'traccar.example.com:443:93.184.216.34,[2606:4700::1111]'],
    'http default port' => ['http://traccar.example.com/traccar', 'traccar.example.com:80:93.184.216.34,[2606:4700::1111]'],
    'explicit port' => ['http://Traccar.Example.com:8082', 'traccar.example.com:8082:93.184.216.34,[2606:4700::1111]'],
]);

test('requestOptions pins a trailing-dot host under both spellings', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->requestOptions('https://traccar.example.com.'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => [
            'traccar.example.com.:443:93.184.216.34',
            'traccar.example.com:443:93.184.216.34',
        ]]]);
});

test('requestOptions looks the host up once and pins exactly that answer', function () {
    config(['wxnotify.mode' => 'cloud']);

    $answers = [['93.184.216.34'], ['10.0.0.5']];
    $guard = new TraccarUrlGuard(function (string $host) use (&$answers): array {
        return array_shift($answers) ?? [];
    });

    expect($guard->requestOptions('https://traccar.example.com'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['traccar.example.com:443:93.184.216.34']]])
        ->and($answers)->toHaveCount(1);
});

test('requestOptions needs no pin for an IP-literal host', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->requestOptions($url))->toBe([]);
})->with([
    'ipv4' => 'http://93.184.216.34:8082',
    'ipv6' => 'http://[2606:4700::1111]:8082',
]);

test('requestOptions refuses what violation refuses, and a host with any private answer', function (string $mode, string $url, array $ips) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard($ips)->requestOptions($url))->toBeNull();
})->with([
    'cloud private answer' => ['cloud', 'https://traccar.example.com', ['93.184.216.34', '10.0.0.5']],
    'cloud private literal' => ['cloud', 'http://10.0.0.5:8082', []],
    'cloud non-ASCII host' => ['cloud', 'http://ä.example.com', ['93.184.216.34']],
    'selfhost metadata answer' => ['selfhost', 'https://traccar.example.com', ['10.0.0.5', '169.254.169.254']],
    'selfhost metadata literal' => ['selfhost', 'http://169.254.169.254', []],
]);

test('requestOptions refuses a host name that does not resolve, in both modes', function (string $mode) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard([])->requestOptions('https://traccar.example.com'))->toBeNull();
})->with(['cloud', 'selfhost']);

test('selfhost pins private and LAN answers', function () {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard(['10.0.0.5', 'fd00::5'])->requestOptions('http://traccar:8082'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['traccar:8082:10.0.0.5,[fd00::5]']]])
        ->and(traccarGuard()->requestOptions('http://192.168.1.50:8082'))->toBe([]);
});

test('violation still allows an unresolved name in selfhost mode (save-time leniency)', function () {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard([])->violation('http://traccar:8082'))->toBeNull();
});

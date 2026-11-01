<?php

use App\Support\WeatherKit\WeatherKitToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;

/**
 * @return array{private: string, public: string}
 */
function wkGenerateKeyPair(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $private);

    return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
}

function wkConfigure(array $overrides = []): void
{
    config(['services.weatherkit' => array_merge([
        'team_id' => 'TEAM',
        'service_id' => 'SERVICE',
        'key_id' => 'KEY123',
        'private_key' => null,
        'private_key_path' => null,
    ], $overrides)]);
}

function wkPart(string $jwt, int $index): array
{
    return json_decode(base64_decode(strtr(explode('.', $jwt)[$index], '-_', '+/')), true);
}

beforeEach(function () {
    Cache::flush();
});

test('a configured key signs an ES256 token with the WeatherKit header and claims', function () {
    $pair = wkGenerateKeyPair();
    wkConfigure(['private_key' => $pair['private']]);

    $signer = new WeatherKitToken;
    $token = $signer->token();

    expect($signer->isConfigured())->toBeTrue()
        ->and($token)->toBeString();

    $header = wkPart($token, 0);
    $claims = wkPart($token, 1);

    expect($header['alg'])->toBe('ES256')
        ->and($header['kid'])->toBe('KEY123')
        ->and($header['id'])->toBe('TEAM.SERVICE')
        ->and($claims['iss'])->toBe('TEAM')
        ->and($claims['sub'])->toBe('SERVICE')
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(3600);

    $decoded = JWT::decode($token, new Key($pair['public'], 'ES256'));
    expect($decoded->iss)->toBe('TEAM');
});

test('the token is cached between calls', function () {
    wkConfigure(['private_key' => wkGenerateKeyPair()['private']]);

    expect((new WeatherKitToken)->token())->toBe((new WeatherKitToken)->token());
});

test('an inline key with escaped newlines still signs', function () {
    $pair = wkGenerateKeyPair();
    wkConfigure(['private_key' => str_replace("\n", '\n', trim($pair['private']))]);

    $token = (new WeatherKitToken)->token();

    expect($token)->toBeString()
        ->and(JWT::decode($token, new Key($pair['public'], 'ES256'))->sub)->toBe('SERVICE');
});

test('a key file path works', function () {
    $pair = wkGenerateKeyPair();
    $path = tempnam(sys_get_temp_dir(), 'wk');
    file_put_contents($path, $pair['private']);
    wkConfigure(['private_key_path' => $path]);

    $signer = new WeatherKitToken;
    $configured = $signer->isConfigured();
    $token = $signer->token();
    unlink($path);

    expect($configured)->toBeTrue()
        ->and($token)->toBeString();
});

test('missing or unusable credentials give no token and never throw', function (array $overrides, bool $configured) {
    wkConfigure(array_merge(['private_key' => wkGenerateKeyPair()['private']], $overrides));

    $signer = new WeatherKitToken;

    expect($signer->isConfigured())->toBe($configured)
        ->and($signer->token())->toBeNull();
})->with([
    'no key id' => [['key_id' => null], false],
    'no team id' => [['team_id' => ''], false],
    'no service id' => [['service_id' => null], false],
    'no key' => [['private_key' => null], false],
    'unreadable key path' => [['private_key' => null, 'private_key_path' => '/nonexistent/AuthKey.p8'], false],
    'garbage pem' => [['private_key' => 'not a pem'], true],
]);

test('changing the team id or service id issues a new token for the same key id', function (string $field) {
    wkConfigure(['private_key' => wkGenerateKeyPair()['private']]);
    $first = (new WeatherKitToken)->token();

    config(['services.weatherkit.'.$field => 'CHANGED']);
    $second = (new WeatherKitToken)->token();

    expect($second)->toBeString()->not->toBe($first)
        ->and(wkPart($second, 1)[$field === 'team_id' ? 'iss' : 'sub'])->toBe('CHANGED');
})->with(['team_id', 'service_id']);

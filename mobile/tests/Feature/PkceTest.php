<?php

use App\Support\Pkce;

test('the challenge matches the RFC 7636 example', function () {
    expect(Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('a pair has a 43-character URL-safe verifier and its S256 challenge', function () {
    ['verifier' => $verifier, 'challenge' => $challenge] = Pkce::pair();

    expect($verifier)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($challenge)->toBe(Pkce::challengeFor($verifier))
        ->and(Pkce::pair()['verifier'])->not->toBe($verifier);
});

test('a state is 32 URL-safe characters and never repeats', function () {
    expect(Pkce::state())->toMatch('/^[A-Za-z0-9_-]{32}$/')->not->toBe(Pkce::state());
});

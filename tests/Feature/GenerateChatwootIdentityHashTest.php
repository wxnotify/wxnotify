<?php

use App\Actions\Support\GenerateChatwootIdentityHash;
use App\Models\User;

test('it hashes the user id with the configured identity secret', function () {
    config(['services.chatwoot.identity_secret' => 'test-secret']);

    $user = User::factory()->create();

    $hash = (new GenerateChatwootIdentityHash)($user);

    expect($hash)->toBe(hash_hmac('sha256', (string) $user->id, 'test-secret'));
});

test('different users produce different hashes', function () {
    config(['services.chatwoot.identity_secret' => 'test-secret']);

    $action = new GenerateChatwootIdentityHash;
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    expect($action($userA))->not->toBe($action($userB));
});

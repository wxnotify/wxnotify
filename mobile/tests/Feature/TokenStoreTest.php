<?php

use App\Support\TokenStore;
use Native\Mobile\SecureStorageAccessibility;

beforeEach(fn () => $this->store = new TokenStore);

test('there is no token until one is stored', function () {
    expect($this->store->token())->toBeNull();

    $this->store->put('1|abc');

    expect($this->store->token())->toBe('1|abc');
});

test('the token is kept after first unlock so background work can read it, and only in secure storage', function () {
    $this->store->put('1|abc');

    expect($this->secureStorage->accessibilityOf('wxnotify_api_token'))->toBe(SecureStorageAccessibility::AfterFirstUnlock)
        ->and($this->secureStorage->items)->toHaveKey('wxnotify_api_token');
});

test('forgetting removes the token', function () {
    $this->store->put('1|abc');
    $this->store->forget();

    expect($this->store->token())->toBeNull();
});

test('a failed secure write is an error, not a silent sign-out', function () {
    $this->secureStorage->failWrites = true;

    expect(fn () => $this->store->put('1|abc'))->toThrow(RuntimeException::class);
});

test('a pending sign-in survives until it is cleared', function () {
    $this->store->begin('verifier-value', 'state-value');

    expect($this->store->pending())->toBe(['verifier' => 'verifier-value', 'state' => 'state-value']);

    $this->store->clearPending();

    expect($this->store->pending())->toBeNull();
});

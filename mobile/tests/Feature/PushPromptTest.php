<?php

use App\NativeComponents\PushPrompt;
use App\Support\PushState;
use App\Support\TokenStore;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Testing\Native;

test('the prompt explains why and offers Enable and Not now', function () {
    (new TokenStore)->put('9|secret');

    Native::test(PushPrompt::class)
        ->assertSee('Get alerts on this phone')
        ->assertSee('Enable notifications')
        ->assertSee('Not now');
});

test('Enable turns push on, asks the OS and continues to the feed', function () {
    (new TokenStore)->put('9|secret');
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(PushPrompt::class)->tap('Enable notifications')->assertReplacedWith('/');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('Not now is remembered and continues to the feed without asking the OS', function () {
    (new TokenStore)->put('9|secret');
    PushNotifications::shouldReceive('enroll')->never();

    Native::test(PushPrompt::class)->tap('Not now')->assertReplacedWith('/');

    expect((new PushState)->declined())->toBeTrue()->and((new PushState)->wantsPush())->toBeFalse();
});

test('a signed-out visitor is sent to sign in', function () {
    Native::test(PushPrompt::class)->assertReplacedWith('/sign-in');
});

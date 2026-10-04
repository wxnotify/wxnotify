<?php

use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\SignInScreen;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

test('the sign-in screen offers one sign-in button that opens the browser handoff', function () {
    Native::test(SignInScreen::class)
        ->assertSee('Sign in to WxNotify')
        ->tap('Sign in to WxNotify');

    expect($this->browser->authUrls)->toHaveCount(1)
        ->and((new TokenStore)->pending())->not->toBeNull();
});

test('the sign-in screen says so when the browser could not be opened', function () {
    $this->browser->authSucceeds = false;

    Native::test(SignInScreen::class)
        ->tap('Sign in to WxNotify')
        ->assertSee('Could not open the sign-in page');
});

test('a valid callback signs the subscriber in and replaces the screen with the feed', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake(['*' => Http::response(['token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5]])]);

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'state-1'])->assertReplacedWith('/');

    expect((new TokenStore)->token())->toBe('5|abc');
});

test('a callback that cannot complete shows an error with a way back', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake();

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'other'])
        ->assertSee('Sign-in could not be completed')
        ->assertDontSee('Signing you in')
        ->tap('Try again')
        ->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a denied callback returns quietly to the sign-in screen', function () {
    (new TokenStore)->begin('v', 'state-1');

    Native::test(AuthDenied::class, ['state' => 'state-1'])->assertReplacedWith('/sign-in');

    expect((new TokenStore)->pending())->toBeNull();
});

test('the sign-in screen steps aside for a subscriber who is already signed in', function () {
    (new TokenStore)->put('5|abc');

    Native::test(SignInScreen::class)->assertReplacedWith('/');
});

test('the sign-in screen steps aside when it resumes after sign-in completed on top of it', function () {
    $screen = Native::test(SignInScreen::class);

    (new TokenStore)->put('5|abc');

    $screen->call('onResume')->assertReplacedWith('/');
});

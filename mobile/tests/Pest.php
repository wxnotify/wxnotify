<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Browser;
use Native\Mobile\Network;
use Native\Mobile\SecureStorage;
use Tests\Fakes\FakeNetwork;
use Tests\Fakes\InMemorySecureStorage;
use Tests\Fakes\RecordingBrowser;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Every native capability the app touches is replaced by an in-memory fake, and any
        // HTTP request a test did not fake fails at once.
        $this->secureStorage = new InMemorySecureStorage;
        $this->browser = new RecordingBrowser;
        $this->network = new FakeNetwork;

        app()->instance(SecureStorage::class, $this->secureStorage);
        app()->instance(Browser::class, $this->browser);
        app()->instance(Network::class, $this->network);

        config(['wxnotify.api_url' => 'https://wx.test']);
        Http::preventStrayRequests();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

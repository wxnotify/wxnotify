<?php

use App\NativeComponents\ProductLink;
use App\Support\ProductLinkTarget;
use App\Support\TokenStore;
use Native\Mobile\Testing\Native;

function productLinkFor(string $page): string
{
    return rtrim(strtr(base64_encode($page), '+/', '-_'), '=');
}

test('a link to a product page of this server opens Alerts with the page over it', function () {
    (new TokenStore)->put('9|secret');

    Native::test(ProductLink::class, ['link' => productLinkFor('https://wx.test/view-product/kbou-tor-202610121500')])
        ->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/kbou-tor-202610121500']);
});

test('a product page on a subdomain of the server\'s site is accepted too', function () {
    (new TokenStore)->put('9|secret');

    Native::test(ProductLink::class, ['link' => productLinkFor('https://products.wx.test/view-product/abc')])->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe(['https://products.wx.test/view-product/abc']);
});

test('anything that is not a View Product page of this server just shows Alerts', function (string $link) {
    (new TokenStore)->put('9|secret');

    Native::test(ProductLink::class, ['link' => $link])->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe([]);
})->with([
    'not base64' => ['not a link!'],
    'an empty link' => [''],
    'another site' => [productLinkFor('https://evil.example/view-product/abc')],
    'a look-alike host' => [productLinkFor('https://wx.test.evil.example/view-product/abc')],
    'plain http' => [productLinkFor('http://wx.test/view-product/abc')],
    'another path' => [productLinkFor('https://wx.test/dashboard')],
    'a query string' => [productLinkFor('https://wx.test/view-product/abc?next=https://evil.example')],
    'credentials in the address' => [productLinkFor('https://user:pass@wx.test/view-product/abc')],
    'a port' => [productLinkFor('https://wx.test:8443/view-product/abc')],
    'an upper-case slug' => [productLinkFor('https://wx.test/view-product/ABC')],
    'a deeper path' => [productLinkFor('https://wx.test/view-product/abc/def')],
]);

test('a signed-out phone is only sent to Alerts, which asks it to sign in', function () {
    (new TokenStore)->forget();

    Native::test(ProductLink::class, ['link' => productLinkFor('https://wx.test/view-product/abc')])->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe([]);
});

test('without a known server nothing is opened', function () {
    config(['wxnotify.api_url' => '']);

    expect(ProductLinkTarget::pageFrom(productLinkFor('https://wx.test/view-product/abc')))->toBeNull();
});

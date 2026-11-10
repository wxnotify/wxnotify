<?php

use App\Models\IngestedProduct;
use App\Models\User;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    $this->make = fn (array $overrides = []) => IngestedProduct::factory()->create([
        'cccc' => 'kmob', 'ttaaii' => 'wwus84', 'awipsid' => 'svrmob', 'wmo_ddhhmm' => '111355',
        'received_at' => now()->subHour(), 'text' => 'THE BULLETIN BODY',
        ...$overrides,
    ]);

    $this->show = fn (string $slug) => $this->withToken($this->token)->getJson("/api/v1/products/{$slug}");
});

test('it needs an app token', function () {
    $product = ($this->make)();

    $this->getJson("/api/v1/products/{$product->view_slug}")->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    $this->withToken($beacon)->getJson("/api/v1/products/{$product->view_slug}")->assertForbidden();
});

test('it returns the page content in the documented shape, with the details in page order', function () {
    $product = ($this->make)([
        'headline' => 'Severe Thunderstorm Warning',
        'vtec_action' => 'NEW', 'vtec_office' => 'kmob', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);

    $response = ($this->show)($product->view_slug)->assertOk()
        ->assertJsonPath('data.title', $product->displayTitle())
        ->assertJsonPath('data.headline', 'Severe Thunderstorm Warning')
        ->assertJsonPath('data.text', 'THE BULLETIN BODY')
        ->assertJsonPath('data.is_test', false)
        ->assertJsonPath('data.view_url', $product->viewUrl())
        ->assertJsonPath('data.details.0', ['label' => 'Office', 'value' => 'KMOB']);

    $labels = collect($response->json('data.details'))->pluck('label')->all();

    expect($labels)->toBe(['Office', 'Issued', 'WMO header', 'AWIPS ID', 'Source', 'VTEC']);
    expect(collect($response->json('data.details'))->firstWhere('label', 'VTEC')['value'])->toContain('NEW.KMOB.SV.W');
});

test('the headline is null when blank', function () {
    $product = ($this->make)(['headline' => '']);

    ($this->show)($product->view_slug)->assertOk()->assertJsonPath('data.headline', null);
});

test('an unknown slug is a 404', function () {
    ($this->show)('no-such-product')->assertNotFound();
});

test('a test product is served outside production but hidden in production', function () {
    $product = ($this->make)(['is_test' => true]);

    ($this->show)($product->view_slug)->assertOk()->assertJsonPath('data.is_test', true);

    app()->instance('env', 'production');

    ($this->show)($product->view_slug)->assertNotFound();
});

test('a slug with upper-case letters or a slash is rejected before any lookup', function () {
    $product = ($this->make)();

    ($this->show)(strtoupper($product->view_slug))->assertNotFound();
    ($this->show)('a/b')->assertNotFound();
});

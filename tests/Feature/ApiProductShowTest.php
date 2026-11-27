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

test('a text product sends its text as stored beside the reflowed reading text', function () {
    $stored = "A strong cold front will move across the region this evening and\r\nbring gusty winds to all of the area.\r\n";
    $product = ($this->make)(['text' => $stored]);

    ($this->show)($product->view_slug)->assertOk()
        ->assertJsonPath('data.text', $stored)
        ->assertJsonPath('data.reading_text', "A strong cold front will move across the region this evening and bring gusty winds to all of the area.\n")
        ->assertJsonPath('data.has_original', true);
});

test('a CAP product never sends XML', function (array $fields, string $expected) {
    $xml = '<?xml version="1.0"?><alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"></alert>';
    $product = ($this->make)(['format' => 'cap', 'text' => $xml, ...$fields]);

    $response = ($this->show)($product->view_slug)->assertOk()
        ->assertJsonPath('data.text', $expected)
        ->assertJsonPath('data.reading_text', $expected)
        ->assertJsonPath('data.has_original', false);

    expect(json_encode($response->json(), JSON_UNESCAPED_SLASHES))->not->toContain('<alert')->not->toContain('<?xml');
})->with([
    'with a description' => [['description' => 'The river is rising.'], 'The river is rising.'],
    'with no readable fields' => [[], 'This alert has no text to show.'],
    'with XML in every readable field' => [[
        'headline' => '<?xml version="1.0"?>',
        'description' => '<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"></alert>',
        'instruction' => '<?xml version="1.0"?><alert></alert>',
    ], 'This alert has no text to show.'],
]);

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

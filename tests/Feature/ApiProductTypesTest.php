<?php

use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
});

test('it needs an app token', function () {
    $this->getJson('/api/v1/product-types')->assertUnauthorized();
});

test('the types are grouped by category in the dashboard order, active and retired only', function () {
    Product::query()->delete();

    $winter = Product::factory()->create(['name' => 'Winter Storm Warning', 'category' => ProductCategory::WinterWeather, 'sort_order' => 2]);
    $tornado = Product::factory()->create(['name' => 'Tornado Warning', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'sort_order' => 1]);
    $retired = Product::factory()->retired()->create(['name' => 'Old Tornado Product', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'sort_order' => 9]);
    Product::factory()->create(['name' => 'Draft Product', 'category' => ProductCategory::WinterWeather, 'status' => ProductStatus::Draft]);
    $uncategorised = Product::factory()->create(['name' => 'Misc Product', 'category' => null]);

    $response = $this->withToken($this->token)->getJson('/api/v1/product-types')->assertOk();

    expect($response->json('data'))->toBe([
        ['category' => 'tornado_and_severe_thunderstorm', 'label' => 'Tornado and Severe Thunderstorm', 'products' => [
            ['id' => $tornado->id, 'name' => 'Tornado Warning'],
            ['id' => $retired->id, 'name' => 'Old Tornado Product'],
        ]],
        ['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => $winter->id, 'name' => 'Winter Storm Warning']]],
        ['category' => 'other', 'label' => 'Other', 'products' => [['id' => $uncategorised->id, 'name' => 'Misc Product']]],
    ]);
});

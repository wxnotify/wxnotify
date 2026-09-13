<?php

use App\Models\DidYouKnow;
use Database\Seeders\DidYouKnowSeeder;

test('it seeds one active fact per entry in the registry', function () {
    (new DidYouKnowSeeder)->run();

    $facts = DidYouKnowSeeder::facts();

    expect(DidYouKnow::count())->toBe(count($facts));

    foreach ($facts as $body) {
        expect(DidYouKnow::where('body', $body)->where('is_active', true)->exists())->toBeTrue();
    }
});

test('running it twice does not duplicate rows', function () {
    (new DidYouKnowSeeder)->run();
    (new DidYouKnowSeeder)->run();

    expect(DidYouKnow::count())->toBe(count(DidYouKnowSeeder::facts()));
});

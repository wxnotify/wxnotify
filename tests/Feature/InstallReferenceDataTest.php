<?php

use App\Actions\Setup\InstallReferenceData;
use App\Models\ChannelType;
use App\Models\DidYouKnow;
use App\Models\Product;
use App\Models\ShapefileLayer;
use App\Models\User;
use App\Support\Setup\ReferenceDataStatus;
use App\Support\Shapefiles\ShapefileLayerDefinitions;

it('installs the product catalog, the tips and the tracked layers on a fresh database', function () {
    $users = User::count();

    expect(ReferenceDataStatus::allInstalled())->toBeFalse();

    expect(app(InstallReferenceData::class)())->toBe(['product_catalog', 'tips', 'shapefile_layers']);

    expect(ReferenceDataStatus::allInstalled())->toBeTrue()
        ->and(User::count())->toBe($users);
});

it('a second run installs nothing', function () {
    app(InstallReferenceData::class)();

    expect(app(InstallReferenceData::class)())->toBe([]);
});

it('leaves an installed catalog alone', function () {
    app(InstallReferenceData::class)();

    Product::query()->whereNotNull('category')->first()->update(['description' => 'custom']);
    $tip = DidYouKnow::query()->first();
    $tip->update(['is_active' => false]);

    app(InstallReferenceData::class)();

    expect(Product::query()->where('description', 'custom')->count())->toBe(1)
        ->and($tip->fresh()->is_active)->toBeFalse();
});

it('installs only the sets that are missing', function () {
    $tip = DidYouKnow::factory()->create(['body' => 'My own tip', 'is_active' => false]);

    expect(app(InstallReferenceData::class)())->toBe(['product_catalog', 'shapefile_layers'])
        ->and(DidYouKnow::count())->toBe(1)
        ->and($tip->fresh()->body)->toBe('My own tip')
        ->and($tip->fresh()->is_active)->toBeFalse();
});

it('status lists the four sets in order with their counts and flags', function () {
    $before = ReferenceDataStatus::sets();

    expect(array_column($before, 'key'))->toBe(['product_catalog', 'channel_types', 'tips', 'shapefile_layers'])
        ->and(array_column($before, 'label'))->each->toBeString()
        ->and($before[0])->toMatchArray(['count' => Product::count(), 'installed' => false])
        ->and($before[1])->toMatchArray(['count' => ChannelType::count(), 'installed' => ChannelType::count() > 0])
        ->and($before[2])->toMatchArray(['count' => 0, 'installed' => false])
        ->and($before[3])->toMatchArray(['count' => 0, 'installed' => false]);

    app(InstallReferenceData::class)();

    $after = ReferenceDataStatus::sets();

    expect($after[0])->toMatchArray(['count' => Product::count(), 'installed' => true])
        ->and($after[2])->toMatchArray(['count' => DidYouKnow::count(), 'installed' => true])
        ->and($after[3])->toMatchArray(['count' => ShapefileLayer::count(), 'installed' => true])
        ->and($after[3]['count'])->toBe(count(ShapefileLayerDefinitions::all()));
});

it('does not count the layers installed while one tracked layer is missing', function () {
    app(InstallReferenceData::class)();
    ShapefileLayer::query()->where('key', ShapefileLayerDefinitions::all()[0]->key)->delete();

    expect(ReferenceDataStatus::allInstalled())->toBeFalse()
        ->and(app(InstallReferenceData::class)())->toBe(['shapefile_layers'])
        ->and(ReferenceDataStatus::allInstalled())->toBeTrue();
});

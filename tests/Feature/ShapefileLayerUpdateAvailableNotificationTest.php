<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;

test('the database payload is Filament-shaped so it appears in the panel notifications bell', function () {
    $layer = ShapefileLayer::factory()->create(['name' => 'Public Zones']);
    $version = ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create([
        'diff_summary' => ['added' => 3, 'removed' => 1, 'changed' => 2],
    ]);
    $staff = User::factory()->create(['is_staff' => true]);

    $staff->notify(new ShapefileLayerUpdateAvailableNotification($version));
    $data = $staff->notifications()->sole()->data;

    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toBe('Public Zones update available')
        ->and($data['body'])->toBe('3 added, 1 removed, 2 changed — review and activate.')
        ->and($data['actions'][0]['url'])->toBe(route('filament.admin.resources.shapefile-layers.view', ['record' => $layer]));
});

test('a staff user\'s Filament-shaped notification is reachable via the same query the panel bell uses', function () {
    $layer = ShapefileLayer::factory()->create();
    $version = ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create();
    $staff = User::factory()->create(['is_staff' => true]);

    $staff->notify(new ShapefileLayerUpdateAvailableNotification($version));

    expect($staff->unreadNotifications()->where('data->format', 'filament')->count())->toBe(1);
});

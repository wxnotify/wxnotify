<?php

use App\Actions\Shapefiles\ActivateAllShapefileVersions;
use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

function stagedShapefileVersion(string $key, ?array $diffSummary = ['added' => 1, 'removed' => 0, 'changed' => 0, 'override_conflicts' => []]): ShapefileLayerVersion
{
    $layer = ShapefileLayer::factory()->create(['key' => $key]);

    return ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
        'diff_summary' => $diffSummary,
    ]);
}

test('it activates every layer whose pending version finished importing', function () {
    $states = stagedShapefileVersion('us_states');
    $counties = stagedShapefileVersion('counties');

    $result = (new ActivateAllShapefileVersions(new ActivateShapefileVersion))();

    expect($result)->toBe(['activated' => 2, 'skipped' => 0, 'failed' => 0])
        ->and($states->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($counties->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($counties->shapefileLayer->fresh()->active_version_id)->toBe($counties->id);
});

test('it leaves a version whose import did not finish and layers with nothing pending alone', function () {
    $unfinished = stagedShapefileVersion('us_states', null);
    $good = stagedShapefileVersion('counties');
    ShapefileLayer::factory()->create(['key' => 'fire_zones']);

    $result = (new ActivateAllShapefileVersions(new ActivateShapefileVersion))();

    expect($result)->toBe(['activated' => 1, 'skipped' => 1, 'failed' => 0])
        ->and($unfinished->fresh()->status)->toBe(ShapefileVersionStatus::Staged)
        ->and($unfinished->shapefileLayer->fresh()->active_version_id)->toBeNull()
        ->and($good->fresh()->status)->toBe(ShapefileVersionStatus::Active);
});

test('one layer failing to activate does not stop the others', function () {
    $broken = stagedShapefileVersion('us_states');
    $good = stagedShapefileVersion('counties');
    $broken->shapefileLayer->update(['key' => 'not_a_tracked_layer']);

    $result = (new ActivateAllShapefileVersions(new ActivateShapefileVersion))();

    expect($result)->toBe(['activated' => 1, 'skipped' => 0, 'failed' => 1])
        ->and($broken->fresh()->status)->toBe(ShapefileVersionStatus::Staged)
        ->and($good->fresh()->status)->toBe(ShapefileVersionStatus::Active);
});

test('the list page offers Activate all only when an imported version is waiting, and activates them', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
    stagedShapefileVersion('us_states', null);

    livewire(ListShapefileLayers::class)->assertActionHidden('activateAll');

    $version = stagedShapefileVersion('counties');

    livewire(ListShapefileLayers::class)
        ->assertActionVisible('activateAll')
        ->callAction('activateAll')
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active);
});

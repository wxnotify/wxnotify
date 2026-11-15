<?php

use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

function addShapefileScanCommandToBoundariesStepMigration(): object
{
    return require database_path('migrations/2026_11_15_110001_add_shapefile_scan_command_to_boundaries_step.php');
}

test('it names the shapefile:scan command in the boundaries step', function () {
    addShapefileScanCommandToBoundariesStepMigration()->up();

    expect(OnboardingStep::query()->where('key', 'boundaries')->sole()->description['en'])
        ->toContain('php artisan shapefile:scan')
        ->toContain('press Activate');
});

test('it leaves an owner-edited boundaries step alone', function () {
    $step = OnboardingStep::query()->where('key', 'boundaries')->sole();
    $step->update(['description' => ['en' => 'My own wording']]);

    addShapefileScanCommandToBoundariesStepMigration()->up();

    expect($step->fresh()->description['en'])->toBe('My own wording');
});

test('down restores the shipped wording', function () {
    $migration = addShapefileScanCommandToBoundariesStepMigration();

    $migration->up();
    $migration->down();

    expect(OnboardingStep::query()->where('key', 'boundaries')->sole()->description['en'])
        ->not->toContain('shapefile:scan')
        ->toContain('If a scan fails, press the button again');
});

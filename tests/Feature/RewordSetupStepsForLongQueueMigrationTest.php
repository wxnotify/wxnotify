<?php

use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

function rewordSetupStepsForLongQueueMigration(): object
{
    return require database_path('migrations/2026_10_30_100001_reword_setup_steps_for_long_queue.php');
}

test('it rewords the boundaries and queue steps to name the shapefile worker', function () {
    rewordSetupStepsForLongQueueMigration()->up();

    expect(OnboardingStep::query()->where('key', 'boundaries')->sole()->description['en'])
        ->toContain('queue-long')
        ->not->toContain('alerts wait')
        ->and(OnboardingStep::query()->where('key', 'scheduler-queue')->sole()->description['en'])
        ->toContain('database_long')
        ->not->toContain('queue:listen');
});

test('it leaves owner-edited steps alone', function () {
    $step = OnboardingStep::query()->where('key', 'boundaries')->sole();
    $step->update(['description' => ['en' => 'My own wording']]);

    rewordSetupStepsForLongQueueMigration()->up();

    expect($step->fresh()->description['en'])->toBe('My own wording');
});

test('down restores the shipped wording', function () {
    $migration = rewordSetupStepsForLongQueueMigration();

    // A rollback undoes the later boundaries rewording first.
    (require database_path('migrations/2026_11_15_110001_add_shapefile_scan_command_to_boundaries_step.php'))->down();
    $migration->down();

    expect(OnboardingStep::query()->where('key', 'boundaries')->sole()->description['en'])
        ->toContain('alerts wait while it runs')
        ->and(OnboardingStep::query()->where('key', 'scheduler-queue')->sole()->description['en'])
        ->toContain('queue:work or queue:listen');

    $migration->up();

    expect(OnboardingStep::query()->where('key', 'boundaries')->sole()->description['en'])->toContain('queue-long');
});

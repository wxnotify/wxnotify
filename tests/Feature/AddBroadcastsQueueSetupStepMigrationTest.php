<?php

use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

function addBroadcastsQueueSetupStepMigration(): object
{
    return require database_path('migrations/2026_11_27_214257_add_broadcasts_queue_to_scheduler_setup_step.php');
}

function schedulerQueueStep(): OnboardingStep
{
    return OnboardingStep::query()->where('key', 'scheduler-queue')->sole();
}

test('the queue step names every queue the delivery worker must read', function () {
    expect(schedulerQueueStep()->description['en'])
        ->toContain('queue:work --queue=deliveries,default,broadcasts)')
        ->toContain('iPhone badge')
        ->toContain('database_long');
});

test('it leaves an owner-edited step alone', function () {
    schedulerQueueStep()->update(['description' => ['en' => 'My own wording']]);

    addBroadcastsQueueSetupStepMigration()->up();
    addBroadcastsQueueSetupStepMigration()->down();

    expect(schedulerQueueStep()->description['en'])->toBe('My own wording');
});

test('down restores the previous wording and up applies the new one again', function () {
    $migration = addBroadcastsQueueSetupStepMigration();
    $new = schedulerQueueStep()->description['en'];

    $migration->down();

    expect(schedulerQueueStep()->description['en'])
        ->toContain('queue:work --queue=deliveries,default)')
        ->not->toContain('broadcasts');

    $migration->up();

    expect(schedulerQueueStep()->description['en'])->toBe($new);
});

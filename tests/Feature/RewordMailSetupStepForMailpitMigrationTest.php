<?php

use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

function rewordMailSetupStepForMailpitMigration(): object
{
    return require database_path('migrations/2026_11_15_100001_reword_mail_setup_step_for_mailpit.php');
}

test('it rewords the mail step to explain the bundled Mailpit', function () {
    rewordMailSetupStepForMailpitMigration()->up();

    expect(OnboardingStep::query()->where('key', 'mail')->sole()->description['en'])
        ->toContain('Mailpit')
        ->toContain('MAIL_MAILER=smtp')
        ->not->toContain('log mailer');
});

test('it leaves an owner-edited mail step alone', function () {
    $step = OnboardingStep::query()->where('key', 'mail')->sole();
    $step->update(['description' => ['en' => 'My own wording']]);

    rewordMailSetupStepForMailpitMigration()->up();

    expect($step->fresh()->description['en'])->toBe('My own wording');
});

test('down restores the shipped wording', function () {
    $migration = rewordMailSetupStepForMailpitMigration();

    $migration->up();
    $migration->down();

    expect(OnboardingStep::query()->where('key', 'mail')->sole()->description['en'])->toContain('log mailer');
});

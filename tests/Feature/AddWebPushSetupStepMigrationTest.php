<?php

use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

const OLD_PUSH_TITLE = 'Set up mobile and browser push (optional)';

function addWebPushSetupStepMigration(): object
{
    return require database_path('migrations/2026_10_22_100001_add_web_push_setup_step.php');
}

/**
 * @return array<string, int>
 */
function ownerSetupStepOrders(): array
{
    $flowId = OnboardingFlow::query()->where('key', 'owner-setup')->value('id');

    return OnboardingStep::query()->where('flow_id', $flowId)->orderBy('sort_order')->pluck('sort_order', 'key')->all();
}

/**
 * Puts the push step back the way the first migration shipped it, with no web-push step.
 */
function restoreShippedPushStepWithoutWebPush(): OnboardingStep
{
    OnboardingStep::query()->where('key', 'web-push')->delete();
    OnboardingStep::query()->where('key', 'billing')->update(['sort_order' => 8]);
    OnboardingStep::query()->where('key', 'support-widget')->update(['sort_order' => 9]);

    $push = OnboardingStep::query()->where('key', 'push')->sole();
    $push->update([
        'title' => ['en' => OLD_PUSH_TITLE],
        'description' => ['en' => 'Only needed if subscribers should get alerts as push notifications. For the mobile app, mount your Firebase service-account JSON file into the container and set FIREBASE_SERVICE_ACCOUNT to its path. For browser push, generate VAPID keys with php artisan webpush:vapid. This ticks when the file can be read and both keys are set.'],
    ]);

    return $push;
}

test('it adds the web-push step right after push and keeps the later steps in order', function () {
    restoreShippedPushStepWithoutWebPush();

    addWebPushSetupStepMigration()->up();

    $webPush = OnboardingStep::query()->where('key', 'web-push')->sole();

    expect(ownerSetupStepOrders())->toBe([
        'basics' => 1, 'weather-feed' => 2, 'reference-data' => 3, 'boundaries' => 4, 'scheduler-queue' => 5,
        'mail' => 6, 'push' => 7, 'web-push' => 8, 'billing' => 9, 'support-widget' => 10,
    ])
        ->and($webPush->condition_key)->toBe('web_push_configured')
        ->and($webPush->is_required)->toBeFalse()
        ->and($webPush->description['en'])->toContain('php artisan webpush:vapid');
});

test('it rewords the push step only while its title and description are still the shipped text', function () {
    $push = restoreShippedPushStepWithoutWebPush();

    addWebPushSetupStepMigration()->up();

    expect($push->fresh()->title)->toBe(['en' => 'Set up mobile push (optional)'])
        ->and($push->fresh()->description['en'])->toContain('FIREBASE_SERVICE_ACCOUNT');
});

test('it leaves an owner-edited push step alone', function () {
    $push = restoreShippedPushStepWithoutWebPush();
    $push->update(['title' => ['en' => 'My own push wording']]);
    $description = $push->fresh()->description;

    addWebPushSetupStepMigration()->up();

    expect($push->fresh()->title)->toBe(['en' => 'My own push wording'])
        ->and($push->fresh()->description)->toBe($description);
});

test('running it again changes nothing', function () {
    restoreShippedPushStepWithoutWebPush();
    addWebPushSetupStepMigration()->up();
    $orders = ownerSetupStepOrders();
    $webPushId = OnboardingStep::query()->where('key', 'web-push')->value('id');

    addWebPushSetupStepMigration()->up();

    expect(ownerSetupStepOrders())->toBe($orders)
        ->and(OnboardingStep::query()->where('key', 'web-push')->sole()->id)->toBe($webPushId);
});

test('rolling it back removes the web-push step and restores the order and wording', function () {
    restoreShippedPushStepWithoutWebPush();
    addWebPushSetupStepMigration()->up();

    addWebPushSetupStepMigration()->down();

    expect(ownerSetupStepOrders()['billing'])->toBe(8)
        ->and(OnboardingStep::query()->where('key', 'web-push')->exists())->toBeFalse()
        ->and(OnboardingStep::query()->where('key', 'push')->sole()->title)->toBe(['en' => OLD_PUSH_TITLE]);
});

<?php

use App\Actions\Setup\InstallReferenceData;
use App\Models\User;
use Wallacemartinss\FilamentOnboarding\Conditions\ConditionRegistry;
use Wallacemartinss\FilamentOnboarding\Facades\Onboarding;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;
use Wallacemartinss\FilamentOnboarding\States\StepState;

function ownerSetupJourneyMigration(): object
{
    return require database_path('migrations/2026_10_18_300001_create_owner_setup_journey.php');
}

/**
 * @return list<string>
 */
function ownerSetupVisibleStepKeys(User $user): array
{
    return Onboarding::for($user)->flow('owner-setup', 'admin')
        ->steps
        ->map(fn (StepState $step): string => $step->key())
        ->all();
}

test('the migration creates the flow and its ten steps in order with their condition keys', function () {
    $flow = OnboardingFlow::query()->where('key', 'owner-setup')->sole();
    $steps = OnboardingStep::query()->where('flow_id', $flow->id)->orderBy('sort_order')->get();

    expect($flow->only(['panel_id', 'visibility_condition', 'is_dismissible', 'is_active']))->toBe([
        'panel_id' => 'admin',
        'visibility_condition' => 'is_owner',
        'is_dismissible' => true,
        'is_active' => true,
    ]);
    expect($steps->map(fn (OnboardingStep $step): array => [
        $step->key,
        $step->sort_order,
        $step->completion_mode->value,
        $step->condition_key,
        $step->visibility_condition,
        $step->cta_url,
        $step->is_required,
    ])->all())->toBe([
        ['basics', 1, 'manual', null, null, null, true],
        ['weather-feed', 2, 'condition', 'feed_product_received', null, null, true],
        ['reference-data', 3, 'condition', 'reference_data_installed', null, '/admin/setup', true],
        ['boundaries', 4, 'condition', 'boundaries_active', null, '/admin/shapefile-layers', true],
        ['scheduler-queue', 5, 'condition', 'scheduler_and_queue_running', null, null, true],
        ['mail', 6, 'condition', 'mail_configured', null, null, true],
        ['push', 7, 'condition', 'push_configured', null, null, false],
        ['web-push', 8, 'condition', 'web_push_configured', null, null, false],
        ['billing', 9, 'condition', 'billing_configured', 'is_cloud_mode', null, true],
        ['support-widget', 10, 'condition', 'support_widget_configured', 'is_cloud_mode', null, true],
    ]);

    $registry = app(ConditionRegistry::class);
    $keys = $steps->pluck('condition_key')->merge($steps->pluck('visibility_condition'))->push($flow->visibility_condition)->filter()->unique();

    expect($keys->reject(fn (string $key): bool => $registry->has($key))->all())->toBe([]);
});

test('the step texts tell the owner the mode default, the scan cost and where billing keys go', function () {
    $descriptions = OnboardingStep::query()->pluck('description', 'key')->map(fn (array $description): string => $description['en']);

    expect($descriptions['basics'])->toContain('WXNOTIFY_MODE=selfhost')
        ->and($descriptions['boundaries'])->toContain('queue-long')
        ->and($descriptions['billing'])->toContain('App Settings > Secrets');
});

test('running the migration again, or after an owner edited a step title, changes nothing', function () {
    $basics = OnboardingStep::query()->where('key', 'basics')->sole();
    $basics->update(['title' => ['en' => 'My own wording']]);

    ownerSetupJourneyMigration()->up();
    ownerSetupJourneyMigration()->up();

    expect(OnboardingFlow::query()->where('key', 'owner-setup')->count())->toBe(1)
        ->and(OnboardingStep::query()->where('flow_id', $basics->flow_id)->count())->toBe(10)
        ->and($basics->fresh()->title)->toBe(['en' => 'My own wording']);
});

test('an owner sees the flow, a staff user who is not an owner and a subscriber do not', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);
    $subscriber = User::factory()->create(['is_staff' => false, 'is_owner' => false]);

    expect(Onboarding::for($owner)->flows('admin')->map->key()->all())->toBe(['owner-setup'])
        ->and(Onboarding::for($staff)->flows('admin')->all())->toBe([])
        ->and(Onboarding::for($subscriber)->flows('admin')->all())->toBe([])
        ->and(Onboarding::for($owner)->flows('dashboard')->all())->toBe([]);
});

test('the admin panel shows the launcher to an owner and not to a staff user who is not an owner', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);

    $this->actingAs($owner)->get('/admin')->assertSee('fio-launcher');

    $this->actingAs($staff)->get('/admin')->assertDontSee('class="fio"', false);
});

test('the progress page lists the steps with their links for an owner and forbids a staff user who is not an owner', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);

    $this->actingAs($owner)->get('/admin/onboarding')
        ->assertSeeInOrder(['Setup checklist', 'Set the basics in .env', 'Connect the weather feed', 'Install the reference data', 'Import the boundaries'])
        ->assertSee('href="/admin/setup"', false);

    $this->actingAs($staff)->get('/admin/onboarding')->assertForbidden();
});

test('only an owner can open the onboarding flows and conditions in the admin panel', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);
    $flowId = OnboardingFlow::query()->where('key', 'owner-setup')->value('id');
    $urls = ['/admin/onboarding-flows', "/admin/onboarding-flows/{$flowId}", "/admin/onboarding-flows/{$flowId}/edit", '/admin/onboarding-conditions'];

    foreach ($urls as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
    }
});

test('cloud-only steps are hidden when the mode is self-hosted and shown in cloud mode', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);

    config(['wxnotify.mode' => 'selfhost']);
    $selfHosted = ownerSetupVisibleStepKeys($owner);
    config(['wxnotify.mode' => 'cloud']);
    $cloud = ownerSetupVisibleStepKeys($owner);

    expect($selfHosted)->toBe(['basics', 'weather-feed', 'reference-data', 'boundaries', 'scheduler-queue', 'mail', 'push', 'web-push'])
        ->and($cloud)->toBe([...$selfHosted, 'billing', 'support-widget']);
});

test('a step ticks itself once its condition is true', function () {
    $owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $before = Onboarding::for($owner)->stepState('reference-data', 'admin')->isCompleted();

    app(InstallReferenceData::class)();

    expect($before)->toBeFalse()
        ->and(Onboarding::for($owner)->stepState('reference-data', 'admin')->isCompleted())->toBeTrue();
});

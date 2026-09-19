<?php

use App\Contracts\PipelineStack;
use App\Filament\Admin\Pages\ResetToFactoryDefaults;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->owner = User::factory()->create(['is_owner' => true, 'password' => 'correct-horse']);
    $this->actingAs($this->owner);
});

test('an owner staff member can reach the page over HTTP', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_owner' => true]));

    $this->get('/admin/reset-to-factory-defaults')->assertOk();
});

test('a non-owner staff member is forbidden over HTTP', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true]));

    $this->get('/admin/reset-to-factory-defaults')->assertForbidden();
});

test('the page is available in production', function () {
    app()->instance('env', 'production');

    expect(ResetToFactoryDefaults::canAccess())->toBeTrue();
});

test('every toggle starts off and the confirm section is hidden until re-authentication', function () {
    livewire(ResetToFactoryDefaults::class)
        ->assertFormSet(['pipeline' => false, 'catalogs' => false, 'subscribers' => false])
        ->assertFormFieldHidden('confirmation');
});

test('a wrong password fails re-authentication and leaves the confirm section hidden', function () {
    livewire(ResetToFactoryDefaults::class)
        ->fillForm(['pipeline' => true])
        ->callAction('reauth', ['password' => 'nope'])
        ->assertFormFieldHidden('confirmation');
});

test('the pipeline acknowledgment appears only while services cannot be stopped', function () {
    livewire(ResetToFactoryDefaults::class)
        ->assertFormFieldHidden('pipeline_stopped')
        ->fillForm(['pipeline' => true])
        ->assertFormFieldVisible('pipeline_stopped');
});

function frReauthenticate($component, array $toggles)
{
    return $component->fillForm($toggles)->callAction('reauth', ['password' => 'correct-horse']);
}

test('a full pipeline reset runs after re-authentication, the acknowledgment and the typed word', function () {
    IngestedProduct::factory()->count(2)->create();
    Location::factory()->create();

    $component = livewire(ResetToFactoryDefaults::class);
    frReauthenticate($component, ['pipeline' => true, 'pipeline_stopped' => true])
        ->assertFormFieldVisible('confirmation')
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(IngestedProduct::count())->toBe(0)
        ->and(Location::count())->toBe(1)
        ->and(Activity::where('description', 'Reset to factory defaults')->count())->toBe(1);
});

test('a wrong typed confirmation blocks the reset', function () {
    IngestedProduct::factory()->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->fillForm(['confirmation' => 'reset'])
        ->callAction('reset')
        ->assertHasFormErrors(['confirmation']);

    expect(IngestedProduct::count())->toBe(1);
});

test('subscriber data requires the instance domain, not RESET', function () {
    config(['app.url' => 'https://alerts.example.com']);
    $sub = User::factory()->create();

    $component = frReauthenticate(livewire(ResetToFactoryDefaults::class), ['subscribers' => true]);

    $component->fillForm(['confirmation' => 'RESET'])->callAction('reset')->assertHasFormErrors(['confirmation']);
    expect(User::whereKey($sub->id)->exists())->toBeTrue();

    $component->fillForm(['confirmation' => 'alerts.example.com'])->callAction('reset')->assertHasNoFormErrors();
    expect(User::whereKey($sub->id)->exists())->toBeFalse()
        ->and(User::whereKey($this->owner->id)->exists())->toBeTrue();
});

test('running the reset without re-authenticating does nothing', function () {
    IngestedProduct::factory()->create();

    livewire(ResetToFactoryDefaults::class)
        ->fillForm(['pipeline' => true, 'pipeline_stopped' => true, 'confirmation' => 'RESET'])
        ->call('runReset');

    expect(IngestedProduct::count())->toBe(1);
});

test('the preview lists live counts for the selected toggles', function () {
    IngestedProduct::factory()->count(3)->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->assertSee('3 ingested products');
});

test('the pipeline acknowledgment is required', function () {
    IngestedProduct::factory()->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => false])
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset')
        ->assertHasFormErrors(['pipeline_stopped']);

    expect(IngestedProduct::count())->toBe(1);
});

test('a non-owner staff user cannot mount the page component', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true]));

    livewire(ResetToFactoryDefaults::class)->assertForbidden();
});

test('a successful reset clears the session flag so a second reset needs re-authentication', function () {
    IngestedProduct::factory()->create();

    $component = frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset');

    expect(session('factory_reset.reauthenticated_at'))->toBeNull();

    IngestedProduct::factory()->create();
    $component->fillForm(['pipeline' => true, 'pipeline_stopped' => true, 'confirmation' => 'RESET'])
        ->assertActionHidden('reset')
        ->call('runReset');

    expect(IngestedProduct::count())->toBe(1);
});

test('re-authentication expires after five minutes', function () {
    IngestedProduct::factory()->create();

    $component = frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true, 'pipeline_stopped' => true])
        ->assertFormFieldVisible('confirmation');

    $this->travel(6)->minutes();

    $component->fillForm(['confirmation' => 'RESET'])
        ->assertActionHidden('reset')
        ->call('runReset');

    expect(IngestedProduct::count())->toBe(1);
});

test('the acknowledgment stays hidden and is not needed when the stack can stop', function () {
    app()->instance(PipelineStack::class, new class implements PipelineStack
    {
        public function canStop(): bool
        {
            return true;
        }

        public function stop(): void {}
    });
    IngestedProduct::factory()->create();

    frReauthenticate(livewire(ResetToFactoryDefaults::class), ['pipeline' => true])
        ->assertFormFieldHidden('pipeline_stopped')
        ->fillForm(['confirmation' => 'RESET'])
        ->callAction('reset')
        ->assertHasNoFormErrors();

    expect(IngestedProduct::count())->toBe(0);
});

test('the reset action is not visible before re-authentication', function () {
    livewire(ResetToFactoryDefaults::class)->assertActionHidden('reset');
});

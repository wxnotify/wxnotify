<?php

use App\Enums\Secret;
use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\SecretSettings;
use App\Support\Secrets;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

const TAB_FAKE_KEY = 'sk_test_FAKE_1234';

function fakeServiceAccount(): string
{
    return json_encode(['client_email' => 'svc@example.iam.gserviceaccount.com', 'private_key' => '-----BEGIN FAKE-----']);
}

function storeTabSecret(string $property, ?string $value): void
{
    $settings = app(SecretSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $this->staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);
});

test('an owner can set a secret and it is stored encrypted', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Secrets::isStored(Secret::StripeSecretKey))->toBeTrue()
        ->and(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a stored secret is never loaded into the page state or the rendered page', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->assertFormSet(['stripe_secret_key' => null])
        ->assertSee('Set. Enter a new value to replace it.')
        ->assertDontSee(TAB_FAKE_KEY, false);

    expect(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY);
});

test('a typed secret is cleared from the page state after saving', function () {
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save')
        ->assertDontSee(TAB_FAKE_KEY, false);

    expect($component->get('data.stripe_secret_key'))->toBeNull()
        ->and(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY);
});

test('leaving a slot blank keeps the stored value', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => '', 'maintenance_mode' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a typed value replaces the stored one and the checkbox clears it', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => 'sk_test_FAKE_5678'])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe('sk_test_FAKE_5678');

    livewire(AppSettings::class)
        ->assertFormFieldVisible('clear_stripe_secret_key')
        ->fillForm(['clear_stripe_secret_key' => true])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBeNull();
});

test('the remove checkbox is hidden for a slot that has nothing stored', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)->assertFormFieldHidden('clear_paddle_api_key');
});

test('the firebase slot only accepts service-account JSON', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => 'not json'])
        ->call('save')
        ->assertHasFormErrors(['firebase_service_account']);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => '{"client_email":"a@b.c"}'])
        ->call('save')
        ->assertHasFormErrors(['firebase_service_account']);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => fakeServiceAccount()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Secrets::isStored(Secret::FirebaseServiceAccount))->toBeTrue();
});

test('a staff user who is not an owner cannot see the tab or write a secret', function () {
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->assertFormFieldHidden('stripe_secret_key')
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY, 'clear_stripe_secret_key' => true])
        ->call('save');

    expect(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('a staff user who is not an owner cannot clear or replace an existing secret either', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => 'sk_test_FAKE_9999', 'clear_stripe_secret_key' => true])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a secret change from the page is audit-logged with markers only', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save');

    $activity = Activity::query()->where('log_name', 'settings')->where('description', 'Updated secrets settings')->sole();

    expect($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->properties['changes'])->toBe(['stripe_secret_key' => ['old' => '[not set]', 'new' => '[set]']])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(TAB_FAKE_KEY);
});

test('saving other settings as an owner does not disturb stored secrets', function () {
    storeTabSecret('paddle_api_key', 'pd_FAKE_1');
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['maintenance_message' => 'Back soon.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->paddle_api_key)->toBe('pd_FAKE_1');
});

test('the status text reflects a secret stored during the same save', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->assertSee('Not set. Falls back to the .env value if one is configured.')
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save')
        ->assertSee('Set. Enter a new value to replace it.');
});

test('a non-owner rendering the page never resolves the secret settings', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->staff);

    $count = 0;
    app()->resolving(SecretSettings::class, function () use (&$count): void {
        $count++;
    });

    livewire(AppSettings::class)->assertOk();

    expect($count)->toBe(0);
});

test('a typed secret is cleared from the page state even when the save fails validation', function () {
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY, 'firebase_service_account' => 'not json'])
        ->call('save')
        ->assertHasFormErrors(['firebase_service_account']);

    expect($component->get('data.stripe_secret_key'))->toBeNull()
        ->and(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY)
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('the remove checkbox wins over a typed value in the same submit', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => 'sk_test_FAKE_5678', 'clear_stripe_secret_key' => true])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBeNull();
});

test('whitespace-only input keeps the stored value and typed values are stored trimmed', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => '   ', 'paddle_api_key' => '  pd_FAKE_2  '])
        ->call('save');

    $settings = app(SecretSettings::class)->refresh();

    expect($settings->stripe_secret_key)->toBe(TAB_FAKE_KEY)
        ->and($settings->paddle_api_key)->toBe('pd_FAKE_2');
});

test('the owner guard in saveSecrets blocks a non-owner even when values reach it', function () {
    $this->actingAs($this->staff);

    $page = app(AppSettings::class);

    Closure::bind(
        fn () => $this->saveSecrets(['stripe_secret_key' => TAB_FAKE_KEY, 'clear_paddle_api_key' => true]),
        $page,
        AppSettings::class,
    )();

    expect(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('saving as a non-owner leaves no secret values in the component state', function () {
    $this->actingAs($this->staff);

    $component = livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save');

    expect(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY);
});

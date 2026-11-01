<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\IntegrationSettings;
use App\Settings\SecretSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

const TILES_PAGE_KEY = 'FAKE_page_tiles_key_456';

function tilesStoreKey(?string $key): void
{
    $secrets = app(SecretSettings::class);
    $secrets->map_tiles_api_key = $key;
    $secrets->save();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $this->staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);
});

test('the map tile fields are hidden until the toggle is on, and the fallback is explained beside it', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->assertFormSet(['map_tiles_enabled' => false])
        ->assertSee('switches itself to OpenStreetMap')
        ->assertFormFieldHidden('map_tiles_provider')
        ->assertFormFieldHidden('map_tiles_style')
        ->assertFormFieldHidden('map_tiles_api_key')
        ->fillForm(['map_tiles_enabled' => true])
        ->assertFormFieldVisible('map_tiles_provider')
        ->assertFormFieldVisible('map_tiles_style')
        ->assertFormFieldVisible('map_tiles_api_key')
        ->assertSee("restrict it to this site's domain");
});

test('the style choices follow the chosen provider and a provider change clears the style', function () {
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, 'map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'transport'])
        ->assertSee('Transport')
        ->assertDontSee('Alidade Smooth');

    $component->fillForm(['map_tiles_provider' => 'stadia'])
        ->assertFormSet(['map_tiles_style' => null])
        ->assertSee('Alidade Smooth');
});

test('an owner saves the tiler, and the key is stored encrypted and never echoed back', function () {
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->fillForm([
            'map_tiles_enabled' => true,
            'map_tiles_provider' => 'mapbox',
            'map_tiles_style' => 'outdoors-v12',
            'map_tiles_api_key' => '  '.TILES_PAGE_KEY.'  ',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertFormSet(['map_tiles_api_key' => null])
        ->assertSee('Set. Enter a new value to replace it.')
        ->assertDontSee(TILES_PAGE_KEY, false);

    $settings = app(IntegrationSettings::class)->refresh();
    $payload = DB::table('settings')->where('group', 'secrets')->where('name', 'map_tiles_api_key')->value('payload');

    expect($settings->map_tiles_enabled)->toBeTrue()
        ->and($settings->map_tiles_provider)->toBe('mapbox')
        ->and($settings->map_tiles_style)->toBe('outdoors-v12')
        ->and(app(SecretSettings::class)->refresh()->map_tiles_api_key)->toBe(TILES_PAGE_KEY)
        ->and($payload)->not->toContain(TILES_PAGE_KEY)
        ->and(json_encode($component->get('data')))->not->toContain(TILES_PAGE_KEY);
});

test('a stored key is never loaded into the form or the rendered page, and a blank field keeps it', function () {
    tilesStoreKey(TILES_PAGE_KEY);
    setIntegrationSetting('map_tiles_enabled', true);
    setIntegrationSetting('map_tiles_provider', 'maptiler');
    setIntegrationSetting('map_tiles_style', 'hybrid');
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->assertFormSet(['map_tiles_api_key' => null])
        ->assertDontSee(TILES_PAGE_KEY, false)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(json_encode($component->get('data')))->not->toContain(TILES_PAGE_KEY)
        ->and(app(SecretSettings::class)->refresh()->map_tiles_api_key)->toBe(TILES_PAGE_KEY);
});

test('turning the tiler on needs a provider, a style of that provider and a key', function (array $fields, string $error) {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, ...$fields])
        ->call('save')
        ->assertHasFormErrors([$error]);

    expect(app(IntegrationSettings::class)->refresh()->map_tiles_enabled)->toBeFalse();
})->with([
    'no provider' => [['map_tiles_style' => 'atlas', 'map_tiles_api_key' => TILES_PAGE_KEY], 'map_tiles_provider'],
    'unknown provider' => [['map_tiles_provider' => 'bing', 'map_tiles_style' => 'atlas', 'map_tiles_api_key' => TILES_PAGE_KEY], 'map_tiles_provider'],
    'no style' => [['map_tiles_provider' => 'thunderforest', 'map_tiles_api_key' => TILES_PAGE_KEY], 'map_tiles_style'],
    'style of another provider' => [['map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'streets-v12', 'map_tiles_api_key' => TILES_PAGE_KEY], 'map_tiles_style'],
    'no key stored or typed' => [['map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'atlas'], 'map_tiles_api_key'],
]);

test('the remove checkbox is offered only while the tiler is off, so a running tiler keeps its key', function () {
    tilesStoreKey(TILES_PAGE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, 'map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'atlas'])
        ->assertFormFieldHidden('clear_map_tiles_api_key')
        ->set('data.clear_map_tiles_api_key', true)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->map_tiles_api_key)->toBe(TILES_PAGE_KEY);
});

test('the remove checkbox clears the stored key while the tiler is off', function () {
    tilesStoreKey(TILES_PAGE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->assertFormFieldVisible('clear_map_tiles_api_key')
        ->fillForm(['clear_map_tiles_api_key' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->map_tiles_api_key)->toBeNull();
});

test('a staff user who is not an owner can switch the tiler but never sees or writes the key', function () {
    tilesStoreKey(TILES_PAGE_KEY);
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, 'map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'atlas'])
        ->assertFormFieldHidden('map_tiles_api_key')
        ->assertSee('Only the owner can enter the API key')
        ->set('data.map_tiles_api_key', 'STAFF_TYPED_KEY')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(IntegrationSettings::class)->refresh()->map_tiles_enabled)->toBeTrue()
        ->and(app(SecretSettings::class)->refresh()->map_tiles_api_key)->toBe(TILES_PAGE_KEY);
});

test('a staff user who is not an owner cannot turn the tiler on before the owner has stored a key', function () {
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, 'map_tiles_provider' => 'thunderforest', 'map_tiles_style' => 'atlas'])
        ->call('save')
        ->assertHasFormErrors(['map_tiles_enabled']);
});

test('the key is never written to the audit log', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['map_tiles_enabled' => true, 'map_tiles_provider' => 'stadia', 'map_tiles_style' => 'outdoors', 'map_tiles_api_key' => TILES_PAGE_KEY])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain(TILES_PAGE_KEY);
});

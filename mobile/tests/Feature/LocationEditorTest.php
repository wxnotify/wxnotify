<?php

use App\NativeComponents\LocationEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

function editorLocation(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Home', 'type' => 'stationary', 'source' => null, 'source_label' => null, 'address' => '1 Main Street, Norman',
        'lat' => 35.2226, 'lng' => -97.4395, 'timezone' => 'America/Chicago', 'update_interval_minutes' => null, 'linked' => null, 'device_name' => null,
        'has_missing_device' => false, 'has_fix' => true, 'last_fix_at' => null, 'position_updated_at' => null, 'stale' => null,
        ...$overrides,
    ];
}

function phoneLocation(array $overrides = []): array
{
    return editorLocation(['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'timezone' => null, 'update_interval_minutes' => 5, 'linked' => true, 'stale' => false, ...$overrides]);
}

/**
 * Fakes the locations endpoint (GET answers the list, POST a created location) plus any extra
 * routes; an extra route with the same key replaces the default. Call once per test: Http::fake
 * calls merge and the first match wins.
 */
function locationFakes(array $extra = [], array $listed = []): void
{
    Http::fake([
        'wx.test/api/v1/locations' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['data' => ['id' => 9, 'label' => 'Cabin']], 201)
            : Http::response(['data' => $listed, 'meta' => ['can_create' => true, 'limit' => 5]]),
        ...$extra,
    ]);
}

function locationEditorTextsIn(array $node): array
{
    $texts = array_values(array_filter([$node['props']['text'] ?? null, $node['props']['label'] ?? null], 'is_string'));

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...locationEditorTextsIn($child)];
    }

    return $texts;
}

/**
 * Matches the action area at the bottom of the form (beside Save location) when it shows the text.
 */
function inLocationActions(string $text): Closure
{
    return fn (array $node): bool => ($node['ref'] ?? null) === 'location-actions' && in_array($text, locationEditorTextsIn($node), true);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a new location offers the current position and an address search, and needs both a name and a position', function () {
    locationFakes();

    Native::test(LocationEditor::class)
        ->assertNavTitle('New location')
        ->assertSee('Use my current position')
        ->assertSee('Search for an address')
        ->tap('Save location')
        ->assertSee('Give this location a name.')
        ->assertSee('Choose a position')
        ->assertNoNavigation();

    Http::assertNothingSent();
});

test('a field\'s message goes away once that field changes', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Norman, Oklahoma', 'lat' => 35.2, 'lng' => -97.4]]])]);

    Native::test(LocationEditor::class)
        ->tap('Save location')
        ->assertSee('Give this location a name.')
        ->input('field-label', 'Cabin')
        ->assertDontSee('Give this location a name.')
        ->assertSee('Choose a position')
        ->set('placeQuery', 'Norman')
        ->tap('Search')
        ->tap('place-0')
        ->assertDontSee('Choose a position');
});

test('an address search offers places, choosing one fills the position and a location is saved', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['data' => [
        ['label' => '1 Lake Road, Norman, Oklahoma, United States', 'lat' => 35.2226, 'lng' => -97.4395],
        ['label' => '2 Lake Road, Norman, Oklahoma, United States', 'lat' => 35.23, 'lng' => -97.44],
    ]])]);

    Native::test(LocationEditor::class)
        ->set('placeQuery', 'Lake Road Norman')
        ->tap('Search')
        ->assertSee('1 Lake Road, Norman, Oklahoma, United States')
        ->tap('place-0')
        ->assertSet('draft.lat', '35.2226')
        ->assertSet('draft.address', '1 Lake Road, Norman, Oklahoma, United States')
        ->assertSee('1 Lake Road, Norman, Oklahoma, United States')
        ->input('field-label', 'Cabin')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/locations' && $r->data() == [
        'label' => 'Cabin', 'lat' => 35.2226, 'lng' => -97.4395, 'address' => '1 Lake Road, Norman, Oklahoma, United States',
    ]);
});

test('the places shown belong to the search that found them, even after the search box changes', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Denver, Colorado', 'lat' => 39.74, 'lng' => -104.99]]])]);

    Native::test(LocationEditor::class)
        ->set('placeQuery', 'Denver')
        ->tap('Search')
        ->assertSee('Results for "Denver"')
        ->set('placeQuery', 'Boulder')
        ->assertSee('Results for "Denver"')
        ->tap('place-0')
        ->assertSet('draft.address', 'Denver, Colorado')
        ->assertSet('draft.label', 'Denver');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/places/search') && $r['q'] === 'Denver');
});

test('an address search needs three characters and reports a busy or offline service', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['error' => 'busy'], 429)]);

    $screen = Native::test(LocationEditor::class)
        ->set('placeQuery', 'ab')
        ->tap('Search')
        ->assertSee('Type at least three characters');

    $screen->set('placeQuery', 'Norman')->tap('Search')->assertSee('busy or unavailable');

    $this->network->connected = false;

    $screen->tap('Search')->assertSee('offline');
});

test('the current position is requested, then its address is looked up, and the position fills the draft', function () {
    locationFakes(['wx.test/api/v1/places/reverse*' => Http::response(['data' => ['address' => '1 Main Street, Norman']])]);

    Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->assertSet('locating', true)
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 35.2226, 'longitude' => -97.4395, 'accuracy' => 20.0, 'id' => 'wxnotify-place'])
        ->assertSet('locating', false)
        ->assertSet('draft.lat', '35.222600')
        ->assertSet('draft.address', '1 Main Street, Norman')
        ->assertSee('1 Main Street, Norman');

    expect($this->geolocation->positionRequests)->toHaveCount(1);
});

test('a position whose address cannot be found is still kept', function () {
    locationFakes(['wx.test/api/v1/places/reverse*' => Http::response(['error' => 'unavailable'], 502)]);

    Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 35.2, 'longitude' => -97.4, 'id' => 'wxnotify-place'])
        ->assertSet('draft.lat', '35.200000')
        ->assertSet('draft.address', '')
        ->assertSee('couldn\'t look up the address');
});

test('without location permission it asks once, retries when granted and otherwise points to the settings', function () {
    locationFakes();
    System::shouldReceive('appSettings')->once();

    $screen = Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->emitNative(LocationReceived::class, ['success' => false, 'error' => 'Location permission denied', 'id' => 'wxnotify-place'])
        ->assertSet('locating', true);

    expect($this->geolocation->permissionRequests)->toBe(1);

    $screen->emitNative(PermissionRequestResult::class, ['location' => 'granted', 'coarseLocation' => 'granted', 'fineLocation' => 'granted', 'id' => 'wxnotify-place']);

    expect($this->geolocation->positionRequests)->toHaveCount(2);

    $screen
        ->emitNative(LocationReceived::class, ['success' => false, 'error' => 'Location permission denied', 'id' => 'wxnotify-place'])
        ->assertSet('locating', false)
        ->assertSet('permissionDenied', true)
        ->assertSee('Location permission is off')
        ->tap('Open settings');

    expect($this->geolocation->permissionRequests)->toBe(1);
});

test('a refused permission request points to the settings at once', function () {
    locationFakes();

    Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->emitNative(LocationReceived::class, ['success' => false, 'error' => 'Location permission not granted', 'id' => 'wxnotify-place'])
        ->emitNative(PermissionRequestResult::class, ['location' => 'permanently_denied', 'coarseLocation' => 'permanently_denied', 'fineLocation' => 'permanently_denied', 'id' => 'wxnotify-place'])
        ->assertSet('locating', false)
        ->assertSee('Location permission is off')
        ->assertSee('Open settings');

    expect($this->geolocation->positionRequests)->toHaveCount(1);
});

test('a position event for another request is ignored', function () {
    locationFakes();

    Native::test(LocationEditor::class)
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 1.0, 'longitude' => 2.0, 'id' => 'something-else'])
        ->assertSet('draft.lat', '');
});

test('an existing stationary location opens filled in, and a rename alone sends only the name', function () {
    (new LocalCache)->rememberLocations([editorLocation()], ['can_create' => true, 'limit' => 5]);
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(['data' => editorLocation(['label' => 'Home base'])])], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertNavTitle('Edit location')
        ->assertSet('draft.label', 'Home')
        ->assertSet('draft.lat', '35.2226')
        ->input('field-label', 'Home base')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/locations/1' && $r->data() == ['label' => 'Home base']);
    expect(collect((new LocalCache)->locations())->firstWhere('id', 1)['label'])->toBe('Home base');
});

test('moving a location sends the new position, its address and a chosen timezone', function () {
    locationFakes([
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Denver, Colorado', 'lat' => 39.74, 'lng' => -104.99]]]),
        'wx.test/api/v1/locations/1' => Http::response(['data' => editorLocation()]),
    ], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->set('placeQuery', 'Denver')
        ->tap('Search')
        ->tap('place-0')
        ->call('pickTimezone', 'Mountain (Denver)')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() == [
        'label' => 'Home', 'lat' => 39.74, 'lng' => -104.99, 'address' => 'Denver, Colorado', 'timezone' => 'America/Denver',
    ]);
});

test('moving a location without choosing a timezone keeps and sends the one it has, and says so', function () {
    locationFakes([
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Denver, Colorado', 'lat' => 39.74, 'lng' => -104.99]]]),
        'wx.test/api/v1/locations/1' => Http::response(['data' => editorLocation()]),
    ], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertSee('The timezone stays as it is unless you choose another')
        ->assertDontSee('Automatic (from the position)')
        ->set('placeQuery', 'Denver')
        ->tap('Search')
        ->tap('place-0')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() == [
        'label' => 'Home', 'lat' => 39.74, 'lng' => -104.99, 'address' => 'Denver, Colorado', 'timezone' => 'America/Chicago',
    ]);
});

test('a mobile location edits only its name and update interval and says what keeps it current', function () {
    locationFakes(['wx.test/api/v1/locations/2' => Http::response(['data' => phoneLocation(['update_interval_minutes' => 10])])], [phoneLocation()]);

    Native::test(LocationEditor::class, ['id' => 2])
        ->assertSee('Kept current by a phone running WxNotify')
        ->assertDontSee('Use my current position')
        ->call('pickInterval', '10 minutes')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() == ['label' => 'Pixel 9', 'update_interval_minutes' => 10]);
});

test('a mobile location that lost its phone says so', function () {
    locationFakes([], [phoneLocation(['linked' => false, 'has_missing_device' => true])]);

    Native::test(LocationEditor::class, ['id' => 2])->assertSee('Not linked to a phone');
});

test('a Traccar location says which device follows it', function () {
    locationFakes([], [editorLocation(['id' => 3, 'type' => 'mobile', 'source' => 'traccar', 'source_label' => 'Traccar', 'device_name' => 'F-150', 'update_interval_minutes' => 15])]);

    Native::test(LocationEditor::class, ['id' => 3])->assertSee('F-150');
});

test('the server refusing a field puts its message on the page and keeps the draft', function () {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(['message' => 'Invalid.', 'errors' => ['label' => ['The label field must not be greater than 255 characters.']]], 422)], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->input('field-label', 'Cabin')
        ->tap('Save location')
        ->assertSee('The label field must not be greater than 255 characters.')
        ->assertSet('draft.label', 'Cabin')
        ->assertNoNavigation();
});

test('the plan limit and maintenance each say so on save, beside the buttons', function (int $status, string $contentType, string $body, string $text) {
    locationFakes([
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Norman', 'lat' => 35.2, 'lng' => -97.4]]]),
        'wx.test/api/v1/locations' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response($body, $status, ['Content-Type' => $contentType])
            : Http::response(['data' => [], 'meta' => []]),
    ]);

    Native::test(LocationEditor::class)
        ->input('field-label', 'Cabin')
        ->set('placeQuery', 'Norman')
        ->tap('Search')
        ->tap('place-0')
        ->tap('Save location')
        ->assertSee($text)
        ->assertElement('column', inLocationActions($text))
        ->assertNoNavigation();
})->with([
    'the plan limit' => [422, 'application/json', '{"error":"location_limit"}', 'Your plan\'s location limit is reached. Remove a location, or upgrade, to add another.'],
    'maintenance' => [503, 'text/plain', 'Back soon.', 'Back soon.'],
]);

test('saving offline keeps the draft and says so beside the buttons', function () {
    locationFakes([], [editorLocation()]);

    $screen = Native::test(LocationEditor::class, ['id' => 1])->input('field-label', 'Cabin');
    $this->network->connected = false;

    $screen->tap('Save location')
        ->assertElement('column', inLocationActions('You\'re offline. Reconnect to save.'))
        ->assertElement('column', inLocationActions('Save location'))
        ->assertSet('draft.label', 'Cabin')
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

test('backing out with edits asks first, beside the buttons', function () {
    locationFakes([], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])->pressBack()->assertWentBack();

    Native::test(LocationEditor::class, ['id' => 1])
        ->input('field-label', 'Cabin')
        ->pressBack()
        ->assertNoNavigation()
        ->assertSee('Discard your changes?')
        ->assertElement('column', inLocationActions('Discard your changes?'))
        ->tap('Discard changes')
        ->assertWentBack();
});

test('a location is deleted after a confirmation', function () {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(null, 204)], [editorLocation(), editorLocation(['id' => 5])]);

    $screen = Native::test(LocationEditor::class, ['id' => 1]);

    $screen->tap('Delete location')->assertSee('can\'t be undone')->tap('Keep location')->assertSet('confirmingDelete', false);

    $screen->tap('Delete location')->tap('Yes, delete')->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/locations/1');
    expect(collect((new LocalCache)->locations())->pluck('id')->all())->toBe([5]);
});

test('a location that alerts still use, or that follows a phone, is not deleted and the reason is shown', function (array $response, string $text) {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response($response, 409)], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->tap('Delete location')
        ->tap('Yes, delete')
        ->assertSee($text)
        ->assertElement('column', inLocationActions($response['message']))
        ->assertNoNavigation();

    expect(collect((new LocalCache)->locations())->pluck('id')->all())->toBe([1]);
})->with([
    'used by alerts' => [['error' => 'location_in_use', 'message' => 'Can\'t delete Home. It\'s used by: Freeze, Storm. Remove it from those alerts first.', 'alerts' => ['Freeze', 'Storm']], 'It\'s used by: Freeze, Storm.'],
    'used by many alerts (the server\'s summary, not the full list)' => [['error' => 'location_in_use', 'message' => 'Can\'t delete Home. It\'s used by: A, B, C, D, E (and 2 more). Remove it from those alerts first.', 'alerts' => ['A', 'B', 'C', 'D', 'E', 'F', 'G']], 'It\'s used by: A, B, C, D, E (and 2 more).'],
    'this phone' => [['error' => 'location_is_a_phone', 'message' => 'Home follows a phone. Stop sharing location on that phone first (Account, in the app).'], 'follows a phone'],
]);

test('a location that no longer exists says so', function () {
    locationFakes([], []);

    Native::test(LocationEditor::class, ['id' => 99])->assertSee('This location no longer exists.');
});

test('a location that cannot be loaded, with nothing remembered, says why and can be retried', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::sequence()
        ->push('Back soon.', 503, ['Content-Type' => 'text/plain'])
        ->push(['data' => [editorLocation()], 'meta' => ['can_create' => true, 'limit' => 5]]),
    ]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertSee('Back soon.')
        ->assertDontSee('Save location')
        ->tap('Try again')
        ->assertSet('ready', true)
        ->assertSet('draft.label', 'Home');
});

test('offline, a remembered location still opens; with nothing remembered the screen says to connect', function () {
    Http::fake();
    $this->network->connected = false;

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertSee('Connect to the internet to open this location.')
        ->assertSee('Try again');

    (new LocalCache)->rememberLocations([editorLocation()], ['can_create' => true, 'limit' => 5]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertSet('ready', true)
        ->assertSet('draft.label', 'Home');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::test(LocationEditor::class)->assertReplacedWith('/sign-in');
});

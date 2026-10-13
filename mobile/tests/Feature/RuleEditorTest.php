<?php

use App\NativeComponents\RuleEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function editorSavedRule(array $overrides = []): array
{
    return [
        'id' => 4, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts', 'is_active' => true,
        'matching_mode' => null, 'product_ids' => [], 'location_ids' => [], 'location_id' => 7, 'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => [],
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'threshold_unit' => '°F', 'timeframe' => 'overnight', 'radius' => null,
        'radius_unit' => 'mi', 'delivery_time' => '06:00', 'quiet_hours_start' => null, 'quiet_hours_end' => null, 'message_body_style' => null,
        'custom_subject_template' => null, 'alert_template_id' => null, 'channel_ids' => [5],
        ...$overrides,
    ];
}

/**
 * Fakes the options endpoint (plus any extra routes). Call once per test: Http::fake calls merge and the first match wins.
 */
function editorFakes(array $extra = [], array $options = []): void
{
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions($options)]), ...$extra]);
}

function bodyOf(Request $request): array
{
    return $request->data();
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a new rule starts with the category choices and no category fields', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->assertNavTitle('New alert rule')
        ->assertSee('What kind of alert?')
        ->assertSee('Weather Alerts')
        ->assertSee('Notify me on')
        ->assertDontSee('Set it up');

    expect((new LocalCache)->options())->not->toBeNull();
});

test('choosing a category shows its fields, and switching clears the previous category\'s', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-threshold')
        ->assertSee('Set it up')
        ->select('field-variable', 'Temperature')
        ->assertSet('draft.variable', 'temperature')
        ->tap('category-lightning')
        ->assertSet('draft.category', 'lightning')
        ->assertSet('draft.variable', '')
        ->assertSet('draft.radius', '15');
});

test('a threshold rule is built from the form and saved', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response(['data' => editorSavedRule()], 201)]);

    Native::test(RuleEditor::class)
        ->tap('category-threshold')
        ->select('field-variable', 'Temperature')
        ->select('field-direction', 'Falls below')
        ->input('field-threshold_value', '32')
        ->select('field-timeframe', 'Overnight')
        ->select('field-location', 'Home')
        ->pickTime('field-delivery_time', '06:30')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alert-rules' && bodyOf($r) == [
        'category' => 'threshold', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'timeframe' => 'overnight', 'location_id' => 7, 'delivery_time' => '06:30',
    ]);
    expect((new LocalCache)->rules()[0]['id'])->toBe(4);
});

test('a weather alert by location is built from products, a mode and locations', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response(['data' => editorSavedRule(['id' => 9, 'category' => 'weather_alerts'])], 201)]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->check('product-10', true)
        ->tap('mode-location')
        ->check('location-7', true)
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && bodyOf($r) == [
        'category' => 'weather_alerts', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'product_ids' => [10], 'matching_mode' => 'location', 'location_ids' => [7],
    ]);
});

test('SPC and WPC offer the risk levels and the modes their products allow, and drop what a changed selection no longer allows', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-spc_wpc')
        ->check('product-21', true)
        ->assertSee('By product polygon')
        ->assertDontSee('By risk area at my location')
        ->check('product-20', true)
        ->assertSee('By risk area at my location')
        ->assertSee('SLGT')
        ->tap('mode-risk_area')
        ->check('risk-SLGT', true)
        ->assertSet('draft.risk_levels', ['SLGT'])
        ->check('product-20', false)
        ->assertSet('draft.matching_mode', '')
        ->assertSet('draft.risk_levels', []);
});

test('saving with required fields missing shows what to fix and sends nothing', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('Save rule')
        ->assertSee('Some details need fixing.')
        ->assertSee('Select at least one product.')
        ->assertSee('Choose how alerts should match.')
        ->assertSee('Choose at least one channel.')
        ->assertNoNavigation();

    Http::assertSentCount(1);
});

test('the server refusing a field puts its message on that field and keeps the draft', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['radius' => ['The radius field must be at least 10.']],
    ], 422)]);

    Native::test(RuleEditor::class)
        ->tap('category-lightning')
        ->select('field-location', 'Home')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertSee('The radius field must be at least 10.')
        ->assertSee('Some details need fixing.')
        ->assertSet('draft.radius', '15')
        ->assertNoNavigation();
});

test('a failed save says why and keeps the draft', function (int $status, string $contentType, string $body, string $text) {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response($body, $status, ['Content-Type' => $contentType])]);

    Native::test(RuleEditor::class)
        ->tap('category-lightning')
        ->select('field-location', 'Home')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertSee($text)
        ->assertSet('draft.location_id', 7)
        ->assertNoNavigation();
})->with([
    'maintenance' => [503, 'text/plain', 'Back soon.', 'Back soon.'],
    'a server error' => [500, 'application/json', '{}', 'Couldn\'t save the rule. Please try again.'],
]);

test('saving a rule that was deleted elsewhere says it no longer exists', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => fn (Request $request) => $request->method() === 'PUT' ? Http::response([], 404) : Http::response(['data' => editorSavedRule()])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->input('field-threshold_value', '30')
        ->tap('Save rule')
        ->assertSee('This rule no longer exists.')
        ->assertDontSee('Couldn\'t save the rule')
        ->assertNoNavigation();
});

test('saving while offline says so and keeps the draft', function () {
    editorFakes();

    $screen = Native::test(RuleEditor::class)->tap('category-lightning')->select('field-location', 'Home')->check('channel-5', true);
    $this->network->connected = false;

    $screen->tap('Save rule')->assertSee('You\'re offline. Reconnect to save.')->assertNoNavigation();
});

test('an existing rule opens filled in and is replaced with PUT', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->assertNavTitle('Edit alert rule')
        ->assertSet('draft.threshold_value', '32')
        ->assertSet('draft.variable', 'temperature')
        ->input('field-threshold_value', '30')
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/alert-rules/4' && bodyOf($r)['threshold_value'] === 30.0 && bodyOf($r)['name'] === 'Freeze');
});

test('an existing rule opens from the remembered list when offline, and a rule that is gone says so', function () {
    (new LocalCache)->rememberOptions(ruleFormOptions());
    (new LocalCache)->rememberRules([editorSavedRule()]);
    $this->network->connected = false;

    Native::test(RuleEditor::class, ['id' => 4])->assertSet('draft.variable', 'temperature');

    $this->network->connected = true;
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions()]), 'wx.test/api/v1/alert-rules/9' => Http::response([], 404)]);

    Native::test(RuleEditor::class, ['id' => 9])->assertSee('This rule no longer exists.')->assertDontSee('What kind of alert?');
});

test('offline with nothing remembered, the form cannot open', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(RuleEditor::class)->assertSee('Connect to the internet to set up an alert rule.')->assertDontSee('What kind of alert?');
});

test('backing out with nothing changed just goes back, and with edits asks first', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);

    Native::test(RuleEditor::class, ['id' => 4])->pressBack()->assertWentBack();

    Native::test(RuleEditor::class, ['id' => 4])
        ->input('field-threshold_value', '30')
        ->pressBack()
        ->assertNoNavigation()
        ->assertSee('Discard your changes?')
        ->tap('Keep editing')
        ->assertSet('confirmingDiscard', false)
        ->pressBack()
        ->tap('Discard changes')
        ->assertWentBack();
});

test('a rule is deleted after a confirmation, and keeping it changes nothing', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => fn (Request $request) => $request->method() === 'DELETE'
        ? Http::response(null, 204)
        : Http::response(['data' => editorSavedRule()])]);
    (new LocalCache)->rememberRules([editorSavedRule(), editorSavedRule(['id' => 5])]);

    $screen = Native::test(RuleEditor::class, ['id' => 4]);

    $screen->tap('Delete rule')->assertSee('can\'t be undone')->tap('Keep rule')->assertSet('confirmingDelete', false);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');

    $screen->tap('Delete rule')->tap('Yes, delete')->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/alert-rules/4');
    expect(collect((new LocalCache)->rules())->pluck('id')->all())->toBe([5]);
});

test('with no channels the form says where to get one', function () {
    editorFakes([], ['channels' => []]);

    Native::test(RuleEditor::class)->assertSee('You have no channels yet.')->assertSee('add one under Settings → Channels');
});

test('an unverified channel is marked as such', function () {
    editorFakes([], ['channels' => [['id' => 5, 'label' => 'Team Discord', 'type' => 'discord', 'type_label' => 'Discord', 'verified' => false]]]);

    Native::test(RuleEditor::class)->assertSee('Team Discord')->assertSee('(unverified)');
});

test('message length, subject and template are hidden from a Free subscriber', function () {
    editorFakes([], ['tier' => ['can_customize_messages' => false]]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->assertDontSee('Message length')
        ->assertDontSee('Message template');
});

test('message length, subject and template appear for a paid subscriber', function () {
    editorFakes([], ['tier' => ['can_customize_messages' => true], 'templates' => ['weather_alerts' => [['id' => 3, 'name' => 'Brief']], 'spc_wpc' => []]]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->assertSee('Message length')
        ->assertSee('Message template')
        ->select('field-alert_template_id', 'Brief')
        ->assertSet('draft.alert_template_id', 3)
        ->tap('category-threshold')
        ->assertDontSee('Message template');
});

test('two locations with the same name can both be chosen', function () {
    editorFakes([], ['locations' => [['id' => 7, 'label' => 'Home'], ['id' => 8, 'label' => 'Home']]]);

    Native::test(RuleEditor::class)
        ->tap('category-daily_summary')
        ->select('field-location', 'Home (2)')
        ->assertSet('draft.location_id', 8)
        ->select('field-location', 'Home')
        ->assertSet('draft.location_id', 7);
});

test('returning from adding a location refreshes the choices and keeps the draft', function () {
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::sequence()
        ->push(['data' => ruleFormOptions()])
        ->push(['data' => ruleFormOptions(['locations' => [['id' => 7, 'label' => 'Home'], ['id' => 8, 'label' => 'Cabin']]])])]);

    Native::test(RuleEditor::class)
        ->tap('category-daily_summary')
        ->set('draft.name', 'Morning')
        ->call('onResume')
        ->select('field-location', 'Cabin')
        ->assertSet('draft.location_id', 8)
        ->assertSet('draft.name', 'Morning');
});

test('an NWS office is found by searching and chosen', function () {
    editorFakes(['wx.test/api/v1/nws-offices' => Http::response(['data' => [
        ['code' => 'ABQ', 'label' => 'ABQ — Albuquerque, NM'], ['code' => 'OKX', 'label' => 'OKX — New York, NY'],
    ]])]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-wfo')
        ->set('officeQuery', 'new york')
        ->tap('Search offices')
        ->assertSee('OKX — New York, NY')
        ->assertDontSee('Albuquerque')
        ->tap('office-OKX')
        ->assertSet('draft.wfo', 'OKX');
});

test('counties and zones are searched by state and chosen with checkboxes', function () {
    editorFakes(['wx.test/api/v1/zones*' => Http::response(['data' => [
        ['code' => 'NYC103', 'label' => 'NYC103 — Suffolk County, NY'], ['code' => 'NYZ072', 'label' => 'NYZ072 — Suffolk, NY'],
    ], 'meta' => ['next_after' => null]])]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-county_zone')
        ->set('zoneState', 'ny')
        ->tap('Search zones')
        ->assertSee('NYC103 — Suffolk County, NY')
        ->check('zone-NYC103', true)
        ->assertSet('draft.county_zone_codes', ['NYC103']);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://wx.test/api/v1/zones?') && str_contains($r->url(), 'state=NY'));
});

test('a zone search needs a state or a few letters', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-county_zone')
        ->tap('Search zones')
        ->assertSee('Enter a two-letter state or at least two letters');

    Http::assertSentCount(1);
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::test(RuleEditor::class)->assertReplacedWith('/sign-in');
});

test('an untouched rule whose risk-level product was retired is saved with its stored mode, levels and products', function () {
    $stored = editorSavedRule([
        'category' => 'spc_wpc', 'category_label' => 'SPC & WPC Alerts', 'variable' => null, 'direction' => null, 'threshold_value' => null,
        'threshold_unit' => null, 'timeframe' => null, 'location_id' => null, 'delivery_time' => null,
        'product_ids' => [99], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT'], 'location_ids' => [7],
    ]);
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => $stored])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->assertSet('draft.matching_mode', 'risk_area')
        ->assertSet('draft.risk_levels', ['SLGT'])
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && bodyOf($r) == [
        'category' => 'spc_wpc', 'name' => 'Freeze', 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'product_ids' => [99], 'matching_mode' => 'risk_area', 'location_ids' => [7], 'risk_levels' => ['SLGT'],
    ]);
});

test('maintenance while opening the form shows the server\'s message and a retry', function () {
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::sequence()
        ->push('Back soon.', 503, ['Content-Type' => 'text/plain'])
        ->push(['data' => ruleFormOptions()])]);

    Native::test(RuleEditor::class)
        ->assertSee('Back soon.')
        ->assertDontSee('What kind of alert?')
        ->tap('Try again')
        ->assertDontSee('Back soon.')
        ->assertSee('What kind of alert?');
});

/**
 * Every visible text and label inside a node, its own included.
 *
 * @return array<int, string>
 */
function editorTextsIn(array $node): array
{
    $texts = array_values(array_filter([$node['props']['text'] ?? null, $node['props']['label'] ?? null], 'is_string'));

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...editorTextsIn($child)];
    }

    return $texts;
}

/**
 * Matches the action area at the bottom of the form (beside Save rule) when it shows the text.
 */
function inEditorActions(string $text): Closure
{
    return fn (array $node): bool => ($node['ref'] ?? null) === 'editor-actions' && in_array($text, editorTextsIn($node), true);
}

test('save feedback and the discard prompt appear beside the buttons, not only at the top of the form', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);

    $screen = Native::test(RuleEditor::class, ['id' => 4])->input('field-threshold_value', '30');
    $this->network->connected = false;

    $screen->tap('Save rule')
        ->assertElement('column', inEditorActions('You\'re offline. Reconnect to save.'))
        ->assertElement('column', inEditorActions('Save rule'))
        ->pressBack()
        ->assertElement('column', inEditorActions('Discard your changes?'))
        ->assertElement('column', inEditorActions('Discard changes'));
});

test('more zone results continue the search that found them, even after the inputs change', function () {
    Http::fake([
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions()]),
        'wx.test/api/v1/zones*' => Http::sequence()
            ->push(['data' => [['code' => 'NYC103', 'label' => 'NYC103 — Suffolk County, NY']], 'meta' => ['next_after' => 'NYC103']])
            ->push(['data' => [['code' => 'NYZ072', 'label' => 'NYZ072 — Suffolk, NY']], 'meta' => ['next_after' => null]]),
    ]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-county_zone')
        ->set('zoneState', 'ny')
        ->set('zoneQuery', 'suff')
        ->tap('Search zones')
        ->assertSee('NYC103 — Suffolk County, NY')
        ->set('zoneState', 'tx')
        ->set('zoneQuery', 'harris')
        ->tap('More results')
        ->assertSee('NYC103 — Suffolk County, NY')
        ->assertSee('NYZ072 — Suffolk, NY')
        ->assertDontSee('More results');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/zones?') && str_contains($r->url(), 'after=NYC103')
        && str_contains($r->url(), 'state=NY') && str_contains($r->url(), 'q=suff'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'state=TX') || str_contains($r->url(), 'harris'));
});

test('a failed delete says why beside the buttons, keeps the rule and stays', function (int $status, string $contentType, string $body, string $text) {
    editorFakes(['wx.test/api/v1/alert-rules/4' => fn (Request $request) => $request->method() === 'DELETE'
        ? Http::response($body, $status, ['Content-Type' => $contentType])
        : Http::response(['data' => editorSavedRule()])]);
    (new LocalCache)->rememberRules([editorSavedRule(), editorSavedRule(['id' => 5])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->tap('Delete rule')
        ->tap('Yes, delete')
        ->assertElement('column', inEditorActions($text))
        ->assertSet('confirmingDelete', false)
        ->assertNoNavigation();

    expect(collect((new LocalCache)->rules())->pluck('id')->all())->toBe([4, 5]);
})->with([
    'maintenance' => [503, 'text/plain', 'Back soon.', 'Back soon.'],
    'a server error' => [500, 'application/json', '{}', 'Couldn\'t delete the rule. Please try again.'],
]);

test('deleting while offline says so, keeps the rule and stays', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);
    (new LocalCache)->rememberRules([editorSavedRule()]);

    $screen = Native::test(RuleEditor::class, ['id' => 4])->tap('Delete rule');
    $this->network->connected = false;

    $screen->tap('Yes, delete')
        ->assertElement('column', inEditorActions('You\'re offline. Reconnect to delete this rule.'))
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    expect(collect((new LocalCache)->rules())->pluck('id')->all())->toBe([4]);
});

test('unchecking and re-checking a channel is not an unsaved edit', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule(['channel_ids' => [5, 6]])])], ['channels' => [
        ['id' => 5, 'label' => 'Discord', 'type' => 'discord', 'type_label' => 'Discord', 'verified' => true],
        ['id' => 6, 'label' => 'Pager', 'type' => 'email', 'type_label' => 'Email', 'verified' => true],
    ]]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->check('channel-5', false)
        ->check('channel-5', true)
        ->assertSet('draft.channel_ids', [6, 5])
        ->pressBack()
        ->assertWentBack();
});

test('a field\'s message goes once the field changes, and the summary once no message is left', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-lightning')
        ->input('field-radius', '5')
        ->tap('Save rule')
        ->assertSee('Choose a location.')
        ->assertSee('Enter a whole-number radius')
        ->select('field-location', 'Home')
        ->assertDontSee('Choose a location.')
        ->assertSee('Some details need fixing.')
        ->input('field-radius', '20')
        ->assertDontSee('Enter a whole-number radius')
        ->assertSee('Some details need fixing.')
        ->check('channel-5', true)
        ->assertDontSee('Choose at least one channel.')
        ->assertDontSee('Some details need fixing.');
});

test('a rule with per-product matching shows a web-only notice instead of its products and matching', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule(mixedApiRule())])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->assertSet('draft.mixed', true)
        ->assertSee('Set it up')
        ->assertSee('This alert matches each product its own way. Edit its matching on the web at wxnotify.com.')
        ->assertDontSee('Mesoscale Discussion')
        ->assertDontSee('Matching mode')
        ->assertDontSee('Risk levels')
        ->assertDontSee('Locations')
        ->assertSee('Notify me on')
        ->assertSee('Quiet hours start (optional)')
        ->assertSee('Name this alert (optional)');
});

test('saving a rule with per-product matching sends its name, channels and quiet hours and none of the product or matching keys', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule(mixedApiRule())])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->input('field-name', 'Outlooks, renamed')
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/alert-rules/4' && bodyOf($r) == [
        'category' => 'spc_wpc', 'name' => 'Outlooks, renamed', 'channel_ids' => [5], 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
    ]);
});

test('a rule that matches its products the same way still shows its products and matching', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule(mixedApiRule(['matching_mode' => 'polygon', 'product_ids' => [21], 'risk_levels' => []]))])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->assertSet('draft.mixed', false)
        ->assertSee('Mesoscale Discussion')
        ->assertSee('Matching mode')
        ->assertDontSee('This alert matches each product its own way');
});

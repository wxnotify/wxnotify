<?php

use App\Actions\Weather\ComposeTemplatedAlertBody;
use App\Enums\AlertTemplateSection;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\IngestedProduct;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function vtecKeyFor(): array
{
    return [
        'vtec_office' => 'KTWC',
        'vtec_phenomenon' => 'FF',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0209',
        'vtec_year' => 2026,
    ];
}

test('composes a labeled-lines body from the checked sections in fixed canonical order', function () {
    $template = AlertTemplate::factory()->create([
        'sections' => [AlertTemplateSection::Impact->value, AlertTemplateSection::Hazard->value, AlertTemplateSection::Source->value],
    ]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create([
        ...vtecKeyFor(),
        'hazard' => 'Flash flooding caused by thunderstorms.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Flash flooding of small creeks and streams.',
    ]);

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe("Hazard: Flash flooding caused by thunderstorms.\nSource: Radar indicated.\nImpact: Flash flooding of small creeks and streams.");
});

test('skips a checked section that is null on this specific event, rather than showing a placeholder', function () {
    $template = AlertTemplate::factory()->create([
        'sections' => [AlertTemplateSection::Hazard->value, AlertTemplateSection::LocationsImpacted->value],
    ]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create([...vtecKeyFor(), 'hazard' => 'Flash flooding.', 'locations_impacted' => null]);

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe('Hazard: Flash flooding.');
});

test('pulls expiration and motion from the matching ActiveWwa row via the VTEC key', function () {
    // Time is frozen so expires->diffForHumans() renders a deterministic
    // string -- unfrozen, "now()->addHour()" and the render-time "now" a
    // moment later drift apart (e.g. "59 minutes from now" instead of
    // "1 hour from now"), making an exact-string assertion flaky.
    Carbon::setTestNow('2026-09-15 12:00:00');

    $template = AlertTemplate::factory()->create(['sections' => [AlertTemplateSection::Expiration->value, AlertTemplateSection::Motion->value]]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    // ActiveWwa is created before the IngestedProduct: IngestedProductObserver
    // (see app/Observers/IngestedProductObserver.php) fires SyncActiveWwa on
    // create, which does its own ActiveWwa::updateOrCreate() for this VTEC
    // key. Creating the IngestedProduct first would race that upsert against
    // this explicit factory create() and violate active_wwas_vtec_key_unique;
    // creating the ActiveWwa row first lets the observer's upsert match and
    // update it instead, leaving the motion_* fields (never in its payload)
    // untouched.
    ActiveWwa::factory()->create([...vtecKeyFor(), 'expires' => now()->addHour(), 'motion_direction_degrees' => 231, 'motion_speed_kt' => 34]);
    $product = IngestedProduct::factory()->create(vtecKeyFor());

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe("Expiration: 1 hour from now\nMotion: Moving SW at 39 mph");
});

test('returns null when the rule has no attached template', function () {
    $rule = AlertRule::factory()->create(['alert_template_id' => null]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null for an spc_wpc-category template, which has no sections of its own', function () {
    $template = AlertTemplate::factory()->spcWpc()->create();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null for a weather_alerts template with zero sections checked', function () {
    $template = AlertTemplate::factory()->create(['sections' => []]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null when every checked section is null for this event', function () {
    $template = AlertTemplate::factory()->create(['sections' => [AlertTemplateSection::LocationsImpacted->value, AlertTemplateSection::CallToAction->value]]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create(['locations_impacted' => null, 'call_to_action' => null]);

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

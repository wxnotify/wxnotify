<?php

use App\Support\RuleFormPresenter;

function rulePresenter(array $overrides = []): RuleFormPresenter
{
    return new RuleFormPresenter(ruleFormOptions($overrides));
}

function draftWith(RuleFormPresenter $presenter, string $category, array $values = []): array
{
    return [...$presenter->blankDraft($category), ...$values];
}

test('a blank draft starts empty, with the lightning radius defaulted', function () {
    $draft = rulePresenter()->blankDraft('lightning');

    expect($draft['category'])->toBe('lightning')
        ->and($draft['radius'])->toBe('15')
        ->and($draft['channel_ids'])->toBe([])
        ->and($draft['product_ids'])->toBe([])
        ->and($draft['location_id'])->toBeNull()
        ->and(rulePresenter()->blankDraft('')['category'])->toBe('');
});

test('which fields show depends on the category and the matching mode', function (string $category, array $values, array $expected) {
    $presenter = rulePresenter();

    expect($presenter->fields(draftWith($presenter, $category, $values)))->toBe($expected);
})->with([
    'weather alerts before a mode is chosen' => ['weather_alerts', [], ['products', 'matching_mode']],
    'weather alerts by location' => ['weather_alerts', ['matching_mode' => 'location'], ['products', 'matching_mode', 'locations']],
    'weather alerts by polygon' => ['weather_alerts', ['matching_mode' => 'polygon'], ['products', 'matching_mode', 'locations']],
    'weather alerts by office' => ['weather_alerts', ['matching_mode' => 'wfo'], ['products', 'matching_mode', 'wfo']],
    'weather alerts by county' => ['weather_alerts', ['matching_mode' => 'county_zone'], ['products', 'matching_mode', 'county_zone_codes']],
    'weather alerts nationwide' => ['weather_alerts', ['matching_mode' => 'nationwide'], ['products', 'matching_mode']],
    'SPC with a risk-level product selected' => ['spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area'], ['products', 'risk_levels', 'matching_mode', 'locations']],
    'SPC without a risk-level product' => ['spc_wpc', ['product_ids' => [21], 'matching_mode' => 'polygon'], ['products', 'matching_mode', 'locations']],
    'threshold' => ['threshold', [], ['variable', 'direction', 'threshold_value', 'timeframe', 'location', 'delivery_time']],
    'daily summary' => ['daily_summary', [], ['location', 'delivery_time']],
    'lightning' => ['lightning', [], ['location', 'radius']],
    'no category yet' => ['', [], []],
]);

test('the allowed matching modes follow the dashboard form', function () {
    $presenter = rulePresenter();
    $values = fn (array $modes) => array_column($modes, 'value');

    expect($values($presenter->allowedModes(draftWith($presenter, 'weather_alerts'))))->toBe(['nationwide', 'location', 'wfo', 'county_zone', 'polygon'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [20]]))))->toBe(['nationwide', 'risk_area'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [21]]))))->toBe(['nationwide', 'polygon'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [20, 21]]))))->toBe(['nationwide', 'risk_area'])
        ->and($presenter->allowedModes(draftWith($presenter, 'threshold')))->toBe([]);
});

test('risk level choices are the union of the selected products\' levels, in order', function () {
    $presenter = rulePresenter();

    expect($presenter->riskLevelChoices(draftWith($presenter, 'spc_wpc', ['product_ids' => [20, 21]])))->toBe(['MRGL', 'SLGT', 'ENH'])
        ->and($presenter->riskLevelChoices(draftWith($presenter, 'spc_wpc', ['product_ids' => [21]])))->toBe([])
        ->and($presenter->riskLevelChoices(draftWith($presenter, 'weather_alerts', ['product_ids' => [20]])))->toBe([]);
});

test('reconcile drops what a changed product selection or mode no longer allows', function () {
    $presenter = rulePresenter();

    $draft = draftWith($presenter, 'spc_wpc', ['product_ids' => [21], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT'], 'location_ids' => [7]]);
    $reconciled = $presenter->reconcile($draft);

    expect($reconciled['matching_mode'])->toBe('')
        ->and($reconciled['risk_levels'])->toBe([])
        ->and($reconciled['location_ids'])->toBe([]);

    $kept = $presenter->reconcile(draftWith($presenter, 'spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT', 'GONE'], 'location_ids' => [7]]));

    expect($kept['matching_mode'])->toBe('risk_area')->and($kept['risk_levels'])->toBe(['SLGT'])->and($kept['location_ids'])->toBe([7]);

    $byOffice = $presenter->reconcile(draftWith($presenter, 'weather_alerts', ['matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_ids' => [7], 'county_zone_codes' => ['NYC103']]));

    expect($byOffice['wfo'])->toBe('OKX')->and($byOffice['location_ids'])->toBe([])->and($byOffice['county_zone_codes'])->toBe([]);
});

test('switching category keeps the shared fields and clears the rest', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);
    $draft = draftWith($presenter, 'threshold', [
        'name' => 'Freeze', 'channel_ids' => [5], 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
        'message_body_style' => 'brief', 'custom_subject_template' => '{product}', 'alert_template_id' => 3,
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '32', 'location_id' => 7, 'delivery_time' => '06:00',
    ]);

    $switched = $presenter->switchCategory($draft, 'weather_alerts');

    expect($switched['category'])->toBe('weather_alerts')
        ->and($switched['name'])->toBe('Freeze')
        ->and($switched['channel_ids'])->toBe([5])
        ->and($switched['quiet_hours_start'])->toBe('22:00')
        ->and($switched['message_body_style'])->toBe('brief')
        ->and($switched['custom_subject_template'])->toBe('{product}')
        ->and($switched['alert_template_id'])->toBeNull()
        ->and($switched['variable'])->toBe('')
        ->and($switched['threshold_value'])->toBe('')
        ->and($switched['location_id'])->toBeNull()
        ->and($switched['delivery_time'])->toBe('')
        ->and($presenter->switchCategory($draft, 'lightning')['radius'])->toBe('15');
});

test('a stored rule becomes a draft the editor can show and send back unchanged', function () {
    $presenter = rulePresenter();
    $rule = [
        'id' => 4, 'name' => 'Freeze', 'category' => 'threshold', 'is_active' => true, 'matching_mode' => null, 'product_ids' => [], 'location_ids' => [], 'location_id' => 7,
        'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => [], 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0,
        'timeframe' => 'overnight', 'radius' => null, 'delivery_time' => '06:00', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => null,
        'message_body_style' => null, 'custom_subject_template' => null, 'alert_template_id' => null, 'channel_ids' => [5],
    ];

    $draft = $presenter->draftFromRule($rule);

    expect($draft['threshold_value'])->toBe('32')
        ->and($draft['wfo'])->toBe('')
        ->and($draft['matching_mode'])->toBe('')
        ->and($draft['quiet_hours_end'])->toBe('')
        ->and($presenter->payload($draft))->toBe([
            'category' => 'threshold', 'name' => 'Freeze', 'channel_ids' => [5], 'quiet_hours_start' => '22:00', 'quiet_hours_end' => null,
            'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'timeframe' => 'overnight', 'location_id' => 7, 'delivery_time' => '06:00',
        ]);
});

test('the payload carries only what the category and mode use', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);

    $weather = $presenter->payload(draftWith($presenter, 'weather_alerts', [
        'product_ids' => [10, 99], 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_ids' => [7], 'county_zone_codes' => ['NYC103'],
        'channel_ids' => [5], 'message_body_style' => 'brief', 'alert_template_id' => 3, 'variable' => 'temperature', 'delivery_time' => '06:00',
    ]));

    expect($weather)->toBe([
        'category' => 'weather_alerts', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'message_body_style' => 'brief', 'custom_subject_template' => null, 'alert_template_id' => 3,
        'product_ids' => [10, 99], 'matching_mode' => 'wfo', 'wfo' => 'OKX',
    ]);

    $lightning = $presenter->payload(draftWith($presenter, 'lightning', ['location_id' => 7, 'radius' => '24', 'channel_ids' => [5]]));

    expect($lightning)->toMatchArray(['category' => 'lightning', 'location_id' => 7, 'radius' => 24])
        ->and($lightning)->not->toHaveKey('alert_template_id')
        ->and($lightning)->not->toHaveKey('product_ids');

    $spc = $presenter->payload(draftWith($presenter, 'spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT'], 'location_ids' => [7], 'channel_ids' => [5]]));

    expect($spc)->toMatchArray(['risk_levels' => ['SLGT'], 'location_ids' => [7]]);
});

test('a Free subscriber\'s payload leaves out message customisation', function () {
    $presenter = rulePresenter();

    $payload = $presenter->payload(draftWith($presenter, 'weather_alerts', ['product_ids' => [10], 'matching_mode' => 'nationwide', 'channel_ids' => [5], 'message_body_style' => 'brief', 'custom_subject_template' => 'x']));

    expect($payload)->not->toHaveKey('message_body_style')->not->toHaveKey('custom_subject_template')->not->toHaveKey('alert_template_id');
});

test('validation mirrors the server\'s required fields', function (string $category, array $values, array $expectedFields) {
    $presenter = rulePresenter();

    expect(array_keys($presenter->validate(draftWith($presenter, $category, $values))))->toBe($expectedFields);
})->with([
    'nothing chosen' => ['', [], ['category', 'channel_ids']],
    'a weather rule with nothing' => ['weather_alerts', [], ['product_ids', 'matching_mode', 'channel_ids']],
    'a weather rule by location without locations' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'location', 'channel_ids' => [5]], ['location_ids']],
    'a weather rule by office without one' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'wfo', 'channel_ids' => [5]], ['wfo']],
    'a weather rule by county without codes' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'county_zone', 'channel_ids' => [5]], ['county_zone_codes']],
    'a complete nationwide rule' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'nationwide', 'channel_ids' => [5]], []],
    'a threshold with nothing' => ['threshold', ['channel_ids' => [5]], ['variable', 'direction', 'threshold_value', 'timeframe', 'location_id', 'delivery_time']],
    'a threshold value that is not a number' => ['threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 'cold', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]], ['threshold_value']],
    'a threshold value above 9999' => ['threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '10000', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]], ['threshold_value']],
    'a threshold value below -9999' => ['threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '-10000', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]], ['threshold_value']],
    'a threshold value at the bounds' => ['threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '-9999', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]], []],
    'a daily summary with nothing' => ['daily_summary', ['channel_ids' => [5]], ['location_id', 'delivery_time']],
    'a lightning radius below the minimum' => ['lightning', ['location_id' => 7, 'radius' => '9', 'channel_ids' => [5]], ['radius']],
    'a lightning radius above the maximum' => ['lightning', ['location_id' => 7, 'radius' => '51', 'channel_ids' => [5]], ['radius']],
    'a lightning radius that is not a whole number' => ['lightning', ['location_id' => 7, 'radius' => '24.5', 'channel_ids' => [5]], ['radius']],
    'a complete lightning rule' => ['lightning', ['location_id' => 7, 'radius' => '15', 'channel_ids' => [5]], []],
]);

test('a threshold value outside the storable range says what is allowed', function () {
    $presenter = rulePresenter();
    $draft = draftWith($presenter, 'threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '1e6', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]]);

    expect($presenter->validate($draft)['threshold_value'])->toBe('Enter a value between -9999 and 9999.');
});

test('small helpers read from the options', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);

    expect($presenter->canCustomizeMessages())->toBeTrue()
        ->and($presenter->unitLabel(draftWith($presenter, 'threshold', ['variable' => 'wind_speed'])))->toBe('mph')
        ->and($presenter->unitLabel(draftWith($presenter, 'threshold')))->toBeNull()
        ->and($presenter->radiusUnit())->toBe('mi')
        ->and($presenter->radiusBounds())->toBe(['min' => 10, 'max' => 50]);
});

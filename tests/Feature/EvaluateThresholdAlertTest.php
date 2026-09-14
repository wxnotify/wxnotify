<?php

use App\Actions\Weather\EvaluateThresholdAlert;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Models\AlertRule;
use Illuminate\Support\Carbon;

function gridpointDataWithTemperatures(array $overnightReadings, array $daytimeReadings = []): array
{
    return [
        'periods' => [
            ['isDaytime' => true, 'startTime' => '2026-09-14T06:00:00-05:00', 'endTime' => '2026-09-14T18:00:00-05:00'],
            ['isDaytime' => false, 'startTime' => '2026-09-14T18:00:00-05:00', 'endTime' => '2026-09-15T06:00:00-05:00'],
        ],
        'series' => [
            'temperature' => array_map(
                fn (array $r) => ['start' => Carbon::parse($r[0]), 'end' => Carbon::parse($r[0])->addHour(), 'value' => $r[1]],
                [...$overnightReadings, ...$daytimeReadings],
            ),
        ],
    ];
}

test('it returns the coldest overnight reading when falling below the threshold', function () {
    $rule = AlertRule::factory()->make([
        'variable' => ThresholdVariable::Temperature,
        'direction' => ThresholdDirection::Below,
        'threshold_value' => 32,
        'timeframe' => ThresholdTimeframe::Overnight,
    ]);

    $data = gridpointDataWithTemperatures([
        ['2026-09-14T23:00:00-05:00', 30.0],
        ['2026-09-15T02:00:00-05:00', 27.0],
        ['2026-09-15T05:00:00-05:00', 29.0],
    ]);

    $result = (new EvaluateThresholdAlert)($rule, $data);

    expect($result)->not->toBeNull()
        ->and($result['value'])->toBe(27.0)
        ->and($result['at']->format('H:i'))->toBe('02:00');
});

test('it returns null when nothing in the window crosses the threshold', function () {
    $rule = AlertRule::factory()->make([
        'variable' => ThresholdVariable::Temperature,
        'direction' => ThresholdDirection::Below,
        'threshold_value' => 20,
        'timeframe' => ThresholdTimeframe::Overnight,
    ]);

    $data = gridpointDataWithTemperatures([['2026-09-14T23:00:00-05:00', 30.0]]);

    expect((new EvaluateThresholdAlert)($rule, $data))->toBeNull();
});

test('a daytime reading is ignored for an overnight-scoped rule', function () {
    $rule = AlertRule::factory()->make([
        'variable' => ThresholdVariable::Temperature,
        'direction' => ThresholdDirection::Above,
        'threshold_value' => 90,
        'timeframe' => ThresholdTimeframe::Overnight,
    ]);

    $data = gridpointDataWithTemperatures(
        overnightReadings: [['2026-09-14T23:00:00-05:00', 70.0]],
        daytimeReadings: [['2026-09-14T14:00:00-05:00', 95.0]],
    );

    expect((new EvaluateThresholdAlert)($rule, $data))->toBeNull();
});

test('any_time considers both the day and overnight windows', function () {
    $rule = AlertRule::factory()->make([
        'variable' => ThresholdVariable::Temperature,
        'direction' => ThresholdDirection::Above,
        'threshold_value' => 90,
        'timeframe' => ThresholdTimeframe::AnyTime,
    ]);

    $data = gridpointDataWithTemperatures(
        overnightReadings: [['2026-09-14T23:00:00-05:00', 70.0]],
        daytimeReadings: [['2026-09-14T14:00:00-05:00', 95.0]],
    );

    $result = (new EvaluateThresholdAlert)($rule, $data);

    expect($result)->not->toBeNull()
        ->and($result['value'])->toBe(95.0);
});

test('it picks the highest reading for a "rises above" rule with multiple qualifying entries', function () {
    $rule = AlertRule::factory()->make([
        'variable' => ThresholdVariable::Temperature,
        'direction' => ThresholdDirection::Above,
        'threshold_value' => 85,
        'timeframe' => ThresholdTimeframe::Day,
    ]);

    $data = gridpointDataWithTemperatures(
        overnightReadings: [],
        daytimeReadings: [
            ['2026-09-14T10:00:00-05:00', 87.0],
            ['2026-09-14T15:00:00-05:00', 94.0],
            ['2026-09-14T17:00:00-05:00', 91.0],
        ],
    );

    $result = (new EvaluateThresholdAlert)($rule, $data);

    expect($result['value'])->toBe(94.0);
});

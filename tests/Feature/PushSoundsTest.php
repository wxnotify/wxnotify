<?php

use App\Actions\Alerts\ResolveAlertPushSound;
use App\Enums\ProductFamily;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use App\Support\PushSounds;
use Illuminate\Support\Collection;

function soundRule(string $category = 'weather_alerts', ?array $sounds = null): AlertRule
{
    $rule = AlertRule::factory()->make(['category' => $category]);
    $rule->forceFill(['push_sounds' => $sounds ?? [
        'short_fuse_warning' => 'alert',
        'other_warning' => 'back',
        'convective_watch' => 'bell',
        'other_watch' => 'correct',
        'advisory' => 'fail',
        'other' => 'harp',
    ]]);

    return $rule;
}

function soundProduct(string $code, ProductFamily $family): Product
{
    return Product::updateOrCreate(['code' => $code], ['name' => $code, 'family' => $family]);
}

function soundBulletin(?Product $product): IngestedProduct
{
    return IngestedProduct::factory()->create(['product_id' => $product?->id]);
}

test('the sound list has the seven slugs, all safe for a file name', function () {
    expect(array_keys(PushSounds::all()))->toBe(['alert', 'back', 'bell', 'correct', 'fail', 'harp', 'notification'])
        ->and(PushSounds::slugs())->each->toMatch('/^[a-z0-9_]+$/')
        ->and(PushSounds::all()['alert'])->toBe('Alert');
});

test('isKnown accepts a listed slug only', function () {
    expect(PushSounds::isKnown('harp'))->toBeTrue()
        ->and(PushSounds::isKnown('nope'))->toBeFalse()
        ->and(PushSounds::isKnown(null))->toBeFalse()
        ->and(PushSounds::isKnown('../x'))->toBeFalse();
});

test('options lists six kinds and seven sounds', function () {
    $options = PushSounds::options();

    expect($options['kinds'])->toHaveCount(6)
        ->and($options['kinds'][0])->toHaveKeys(['value', 'label', 'description'])
        ->and($options['kinds'][0]['value'])->toBe('short_fuse_warning')
        ->and($options['sounds'])->toHaveCount(7)
        ->and($options['sounds'][0])->toBe(['value' => 'alert', 'label' => 'Alert']);
});

test('a product code and family resolve to the rule\'s sound for that kind', function (string $code, ProductFamily $family, string $expected) {
    $bulletin = soundBulletin(soundProduct($code, $family));

    expect((new ResolveAlertPushSound)(soundRule(), $bulletin))->toBe($expected);
})->with([
    'tornado warning' => ['TO.W', ProductFamily::Warning, 'alert'],
    'severe thunderstorm warning' => ['SV.W', ProductFamily::Warning, 'alert'],
    'flash flood warning' => ['FF.W', ProductFamily::Warning, 'alert'],
    'dust storm warning' => ['DS.W', ProductFamily::Warning, 'alert'],
    'special marine warning' => ['SQ.W', ProductFamily::Warning, 'alert'],
    'winter storm warning' => ['WS.W', ProductFamily::Warning, 'back'],
    'tornado watch' => ['TO.A', ProductFamily::Watch, 'bell'],
    'severe thunderstorm watch' => ['SV.A', ProductFamily::Watch, 'bell'],
    'flash flood watch' => ['FF.A', ProductFamily::Watch, 'correct'],
    'advisory' => ['WI.Y', ProductFamily::Advisory, 'fail'],
    'statement' => ['FL.S', ProductFamily::Statement, 'harp'],
    'spc product' => ['ZZ.SPC', ProductFamily::SpcWpc, 'harp'],
]);

test('no product resolves to the other kind', function () {
    expect((new ResolveAlertPushSound)(soundRule()))->toBe('harp')
        ->and((new ResolveAlertPushSound)(soundRule(), soundBulletin(null)))->toBe('harp');
});

test('the matched segments decide over the bulletin, and the highest priority kind wins', function () {
    $bulletin = soundBulletin(soundProduct('WI.Y', ProductFamily::Advisory));
    $segments = new Collection([
        IngestedProductSegment::factory()->create([
            'ingested_product_id' => $bulletin->id,
            'product_id' => soundProduct('WI.Y', ProductFamily::Advisory)->id,
        ]),
        IngestedProductSegment::factory()->create([
            'ingested_product_id' => $bulletin->id,
            'product_id' => soundProduct('TO.W', ProductFamily::Warning)->id,
        ]),
    ]);

    expect((new ResolveAlertPushSound)(soundRule(), $bulletin, $segments))->toBe('alert');
});

test('daily summary and outlook report rules use the other sound', function (string $category) {
    $bulletin = soundBulletin(soundProduct('TO.W', ProductFamily::Warning));

    expect((new ResolveAlertPushSound)(soundRule($category), $bulletin))->toBe('harp');
})->with(['daily_summary', 'outlook_report']);

test('no sound is chosen without a stored entry or with an unknown slug', function (?array $sounds) {
    $bulletin = soundBulletin(soundProduct('TO.W', ProductFamily::Warning));
    $rule = soundRule(sounds: $sounds);
    $rule->forceFill(['push_sounds' => $sounds]);

    expect((new ResolveAlertPushSound)($rule, $bulletin))->toBeNull();
})->with([
    'null' => [null],
    'kind unset' => [['advisory' => 'fail']],
    'unknown slug' => [['short_fuse_warning' => '../x']],
]);

<?php

use App\Models\AlertRule;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

function convertForecastDiscussionRules(): void
{
    (require database_path('migrations/2026_10_02_100001_convert_forecast_discussion_rules_to_weather_alerts.php'))->up();
}

test('a legacy forecast_discussion rule becomes a WFO-mode Weather Alerts rule with AFD attached', function () {
    $this->seed(ProductCatalogSeeder::class);
    $user = User::factory()->create();
    DB::table('alert_rules')->insert([
        'user_id' => $user->id, 'category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    convertForecastDiscussionRules();

    $rule = AlertRule::sole();

    expect($rule->category->value)->toBe('weather_alerts')
        ->and($rule->matching_mode->value)->toBe('wfo')
        ->and($rule->wfo)->toBe('TWC')
        ->and($rule->products->pluck('id')->all())->toBe([Product::where('code', 'AFD')->sole()->id]);
});

test('the conversion leaves every other rule untouched', function () {
    $this->seed(ProductCatalogSeeder::class);
    $other = AlertRule::factory()->create();

    convertForecastDiscussionRules();

    expect($other->fresh()->category->value)->toBe('threshold')
        ->and($other->products)->toBeEmpty();
});

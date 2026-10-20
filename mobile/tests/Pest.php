<?php

use App\Services\AppBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Browser;
use Native\Mobile\Geolocation;
use Native\Mobile\Network;
use Native\Mobile\SecureStorage;
use Tests\Fakes\FakeNetwork;
use Tests\Fakes\InMemorySecureStorage;
use Tests\Fakes\RecordingAppBadge;
use Tests\Fakes\RecordingBrowser;
use Tests\Fakes\RecordingGeolocation;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Every native capability the app touches is replaced by an in-memory fake, and any
        // HTTP request a test did not fake fails at once.
        $this->secureStorage = new InMemorySecureStorage;
        $this->browser = new RecordingBrowser;
        $this->network = new FakeNetwork;
        $this->geolocation = new RecordingGeolocation;
        $this->appBadge = new RecordingAppBadge;

        app()->instance(SecureStorage::class, $this->secureStorage);
        app()->instance(Browser::class, $this->browser);
        app()->instance(Network::class, $this->network);
        app()->instance(Geolocation::class, $this->geolocation);
        app()->instance(AppBadge::class, $this->appBadge);

        config(['wxnotify.api_url' => 'https://wx.test']);
        Http::preventStrayRequests();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function ruleFormOptions(array $overrides = []): array
{
    return [
        'categories' => [
            ['value' => 'weather_alerts', 'label' => 'Weather Alerts', 'description' => 'Warnings.'],
            ['value' => 'daily_summary', 'label' => 'Daily Weather Summary', 'description' => 'Daily.'],
            ['value' => 'threshold', 'label' => 'Threshold Alerts', 'description' => 'Values.'],
            ['value' => 'spc_wpc', 'label' => 'SPC & WPC Alerts', 'description' => 'Outlooks.'],
            ['value' => 'lightning', 'label' => 'Lightning Alerts', 'description' => 'Strikes.'],
        ],
        'products' => [
            'weather_alerts' => [['category' => 'tornado', 'label' => 'Tornado', 'products' => [
                ['id' => 10, 'name' => 'Tornado Warning', 'description' => null, 'risk_levels' => []],
                ['id' => 11, 'name' => 'Tornado Watch', 'description' => null, 'risk_levels' => []],
            ]]],
            'spc_wpc' => [
                ['category' => 'convective', 'label' => 'Convective Outlooks', 'products' => [
                    ['id' => 20, 'name' => 'Day 1 Outlook', 'description' => null, 'risk_levels' => ['MRGL', 'SLGT', 'ENH']],
                    ['id' => 21, 'name' => 'Mesoscale Discussion', 'description' => null, 'risk_levels' => []],
                ]],
            ],
        ],
        'matching_modes' => [
            ['value' => 'nationwide', 'label' => 'All products, any location', 'description' => 'n'],
            ['value' => 'location', 'label' => 'By my location', 'description' => 'l'],
            ['value' => 'wfo', 'label' => 'By issuing office (WFO)', 'description' => 'w'],
            ['value' => 'county_zone', 'label' => 'By county/zone (UGC)', 'description' => 'c'],
            ['value' => 'polygon', 'label' => 'By product polygon', 'description' => 'p'],
            ['value' => 'risk_area', 'label' => 'By risk area at my location', 'description' => 'r'],
        ],
        'thresholds' => [
            'variables' => [['value' => 'temperature', 'label' => 'Temperature', 'unit' => '°F', 'decimals' => 1], ['value' => 'wind_speed', 'label' => 'Wind speed', 'unit' => 'mph', 'decimals' => 1]],
            'directions' => [['value' => 'above', 'label' => 'Rises above'], ['value' => 'below', 'label' => 'Falls below']],
            'timeframes' => [['value' => 'day', 'label' => 'During the day'], ['value' => 'overnight', 'label' => 'Overnight']],
        ],
        'lightning' => ['unit' => 'mi', 'min' => 10, 'max' => 50, 'default' => 15],
        'tier' => ['can_customize_messages' => false],
        'message_body_styles' => [['value' => 'full', 'label' => 'Full'], ['value' => 'brief', 'label' => 'Brief']],
        'channels' => [['id' => 5, 'label' => 'Discord', 'type' => 'discord', 'type_label' => 'Discord', 'verified' => true]],
        'templates' => ['weather_alerts' => [], 'spc_wpc' => []],
        'locations' => [['id' => 7, 'label' => 'Home']],
        'can_create_location' => true,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed> a stored SPC & WPC rule whose products match differently, as the API returns it
 */
function mixedApiRule(array $overrides = []): array
{
    return [
        'id' => 4, 'name' => 'Outlooks', 'category' => 'spc_wpc', 'is_active' => true, 'matching_mode' => 'mixed', 'product_ids' => [20, 21], 'location_ids' => [],
        'location_id' => null, 'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => ['SLGT'], 'variable' => null, 'direction' => null, 'threshold_value' => null,
        'timeframe' => null, 'radius' => null, 'delivery_time' => null, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00', 'message_body_style' => 'brief',
        'custom_subject_template' => null, 'alert_template_id' => 3, 'channel_ids' => [5],
        ...$overrides,
    ];
}

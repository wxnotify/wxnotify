<?php

use App\Filament\Dashboard\Widgets\LocationForecastWidget;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs(User::factory()->create());
});

test('a fixless mobile location returns no periods without calling the forecast API', function () {
    Http::fake();

    $location = Location::factory()->mobile()->create();

    $periods = livewire(LocationForecastWidget::class, ['record' => $location])
        ->instance()
        ->getPeriods();

    expect($periods)->toBe([]);
    Http::assertNothingSent();
});

test('a mobile location with a fix still fetches its forecast normally', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast',
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1/forecast' => Http::response([
            'properties' => [
                'periods' => [
                    ['name' => 'Today', 'temperature' => 80, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
                ],
            ],
        ]),
    ]);

    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create();

    $periods = livewire(LocationForecastWidget::class, ['record' => $location])
        ->instance()
        ->getPeriods();

    expect($periods)->not->toBe([]);
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'api.weather.gov'));
});

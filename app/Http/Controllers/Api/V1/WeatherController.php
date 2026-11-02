<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Weather\GetPlaceWeather;
use App\Actions\Weather\GetUserWeatherPlaces;
use App\Actions\Weather\GetWeatherAttribution;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlaceWeatherResource;
use App\Models\Location;
use App\Support\FormatPlaceWeather;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

class WeatherController extends Controller
{
    public function __invoke(Request $request, GetUserWeatherPlaces $places, GetPlaceWeather $weather, GetWeatherAttribution $attribution): JsonResource
    {
        $request->validate(['location' => ['sometimes', 'integer']]);

        $user = $request->user();
        $located = $places($user, $request->user()->currentAccessToken()?->id);
        $selected = null;
        $card = null;
        $credit = null;

        if ($located->isNotEmpty()) {
            $selected = $request->has('location')
                ? $located->firstWhere('id', $request->integer('location'))
                : $located->first();

            abort_if($selected === null, 404);

            $card = $this->card($selected, $weather, Units::for($user));

            if ($card['available']) {
                $credit = $this->attribution($attribution);
            }
        } elseif ($request->has('location')) {
            abort(404);
        }

        return (new JsonResource([
            'places' => $located->map(fn (Location $place): array => [
                'id' => $place->id,
                'label' => (string) $place->label,
                'is_phone' => $place->isPhoneApp(),
            ])->all(),
            'selected' => $card === null ? null : new PlaceWeatherResource($card),
            'attribution' => $credit,
        ]))->additional(['meta' => ['has_places' => $located->isNotEmpty(), 'generated_at' => now()->toIso8601String()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Location $place, GetPlaceWeather $weather, Units $units): array
    {
        try {
            $result = $weather($place);

            return $result === null
                ? FormatPlaceWeather::unavailable($place)
                : FormatPlaceWeather::forUser($result, $units, $place);
        } catch (Throwable $e) {
            report($e);

            return FormatPlaceWeather::unavailable($place);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attribution(GetWeatherAttribution $attribution): array
    {
        try {
            return $attribution();
        } catch (Throwable $e) {
            report($e);

            return GetWeatherAttribution::fallback();
        }
    }
}

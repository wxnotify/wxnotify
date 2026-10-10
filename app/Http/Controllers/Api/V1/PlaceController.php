<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Actions\Locations\SearchPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReversePlaceRequest;
use App\Http\Requests\SearchPlacesRequest;
use Illuminate\Http\JsonResponse;

/**
 * The picker's two lookups. Nothing is logged or stored: the query text and coordinates are the
 * subscriber's own place and stay out of the log and the activity log.
 */
class PlaceController extends Controller
{
    public function search(SearchPlacesRequest $request, SearchPlaces $search): JsonResponse
    {
        $result = $search($request->validated('q'));

        return $this->refusal($result['status']) ?? response()->json(['data' => $result['places']]);
    }

    public function reverse(ReversePlaceRequest $request, ReverseGeocodeCoordinates $reverse): JsonResponse
    {
        $result = $reverse((float) $request->validated('lat'), (float) $request->validated('lng'));

        return $this->refusal($result['status']) ?? response()->json(['data' => ['address' => $result['address']]]);
    }

    private function refusal(string $status): ?JsonResponse
    {
        return match ($status) {
            'throttled' => response()->json(['error' => 'busy', 'message' => 'Search is busy. Try again in a moment.'], 429)->header('Retry-After', '1'),
            'failed' => response()->json(['error' => 'unavailable', 'message' => 'Address lookup is unavailable right now.'], 502),
            default => null,
        };
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\RecordBeaconPoints;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationPointsRequest;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LocationController extends Controller
{
    /**
     * The beacon's position upload. The plugin ignores the body and never retries, so a stale
     * point is accepted (204) and simply not applied.
     */
    public function store(StoreLocationPointsRequest $request, RecordBeaconPoints $record): Response
    {
        $beacon = $request->user()->currentAccessToken();

        abort_unless($beacon instanceof PersonalAccessToken, 403);

        $location = $record($beacon, $request->validated('points'));

        abort_if($location === null, 404);

        return response()->noContent();
    }
}

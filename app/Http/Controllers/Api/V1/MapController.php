<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Map\GetMapSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MapSnapshotResource;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function __invoke(Request $request, GetMapSnapshot $snapshot): MapSnapshotResource
    {
        return new MapSnapshotResource($snapshot($request->user(), $request->user()->currentAccessToken()?->id));
    }
}

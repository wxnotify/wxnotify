<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\FindAppAlert;
use App\Actions\Alerts\ListAppAlerts;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAppAlertsRequest;
use App\Http\Resources\Api\V1\AlertDetailResource;
use App\Http\Resources\Api\V1\AlertResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    public function index(ListAppAlertsRequest $request, ListAppAlerts $list): AnonymousResourceCollection
    {
        $page = $list($request->user(), (int) $request->validated('limit', 25), $request->validated('before'));

        return AlertResource::collection($page['alerts'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor']]]);
    }

    public function show(Request $request, int $id, FindAppAlert $find): AlertDetailResource
    {
        return new AlertDetailResource($find($request->user(), $id) ?? abort(404));
    }
}

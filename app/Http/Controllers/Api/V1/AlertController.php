<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\FindAppAlert;
use App\Actions\Alerts\ListAppAlerts;
use App\Actions\Alerts\MarkAppAlertsRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAppAlertsRequest;
use App\Http\Resources\Api\V1\AlertDetailResource;
use App\Http\Resources\Api\V1\AlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    public function index(ListAppAlertsRequest $request, ListAppAlerts $list, CountUnreadAppAlerts $countUnread): AnonymousResourceCollection
    {
        $page = $list($request->user(), (int) $request->validated('limit', 25), $request->validated('before'));

        return AlertResource::collection($page['alerts'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor'], 'unread_count' => $countUnread($request->user())]]);
    }

    public function show(Request $request, int $id, FindAppAlert $find): AlertDetailResource
    {
        return new AlertDetailResource($find($request->user(), $id) ?? abort(404));
    }

    public function read(Request $request, int $id, MarkAppAlertsRead $mark): JsonResponse
    {
        $unread = $mark->one($request->user(), $id) ?? abort(404);

        return response()->json(['data' => ['unread_count' => $unread]]);
    }

    public function readAll(Request $request, MarkAppAlertsRead $mark): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $mark->all($request->user())]]);
    }
}

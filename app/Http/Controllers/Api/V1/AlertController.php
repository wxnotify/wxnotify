<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\FindAppAlert;
use App\Actions\Alerts\ListAppAlerts;
use App\Actions\Alerts\MarkAppAlertsRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAppAlertsRequest;
use App\Http\Requests\MarkAllAppAlertsReadRequest;
use App\Http\Requests\MarkAppAlertReadByRefRequest;
use App\Http\Resources\Api\V1\AlertDetailResource;
use App\Http\Resources\Api\V1\AlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    /**
     * `meta.as_of` is taken before any query, so a row inserted while the request runs is after it; the app
     * sends it back as mark-all's `through`.
     */
    public function index(ListAppAlertsRequest $request, ListAppAlerts $list, CountUnreadAppAlerts $countUnread): AnonymousResourceCollection
    {
        $asOf = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $page = $list($request->user(), (int) $request->validated('limit', 25), $request->validated('before'));

        return AlertResource::collection($page['alerts'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor'], 'unread_count' => $countUnread($request->user()), 'as_of' => $asOf]]);
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

    public function readByRef(MarkAppAlertReadByRefRequest $request, MarkAppAlertsRead $mark): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $mark->byRef($request->user(), $request->validated('ref'))]]);
    }

    public function readAll(MarkAllAppAlertsReadRequest $request, MarkAppAlertsRead $mark): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $mark->all($request->user(), $request->through())]]);
    }
}

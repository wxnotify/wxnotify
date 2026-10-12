<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\GetActiveAlertsForUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActiveAlertResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActiveAlertController extends Controller
{
    public function __invoke(Request $request, GetActiveAlertsForUser $active): AnonymousResourceCollection
    {
        $result = $active($request->user());

        return ActiveAlertResource::collection($result['events'])
            ->additional(['meta' => ['has_places' => $result['has_places'], 'generated_at' => $result['generated_at']]]);
    }
}

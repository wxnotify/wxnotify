<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertRuleOptionsController extends Controller
{
    public function __invoke(Request $request, BuildAlertRuleOptions $options): JsonResponse
    {
        return response()->json(['data' => $options($request->user())]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\DeleteAlertRule;
use App\Actions\Alerts\SaveAlertRule;
use App\Actions\Alerts\SetAlertRuleActive;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAlertRuleRequest;
use App\Http\Requests\SetAlertRuleActiveRequest;
use App\Http\Resources\Api\V1\AlertRuleResource;
use App\Models\AlertRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AlertRuleController extends Controller
{
    private const WITH = ['products:id,name,risk_level_options', 'location:id,label', 'locations:id', 'productLocations:id', 'notificationChannels:id'];

    public function index(Request $request): AnonymousResourceCollection
    {
        return AlertRuleResource::collection(
            $request->user()->alertRules()->with(self::WITH)->orderByDesc('id')->get()
        );
    }

    public function show(Request $request, int $id): AlertRuleResource
    {
        return new AlertRuleResource($this->find($request, $id));
    }

    public function store(SaveAlertRuleRequest $request, SaveAlertRule $save): JsonResponse
    {
        $rule = $save($request->user(), $request->ruleData())->load(self::WITH);

        return (new AlertRuleResource($rule))->response()->setStatusCode(201);
    }

    public function update(SaveAlertRuleRequest $request, int $id, SaveAlertRule $save): AlertRuleResource
    {
        $rule = $save($request->user(), $request->ruleData(), $this->find($request, $id))->load(self::WITH);

        return new AlertRuleResource($rule);
    }

    public function setActive(SetAlertRuleActiveRequest $request, int $id, SetAlertRuleActive $set): AlertRuleResource
    {
        return new AlertRuleResource($set($request->user(), $this->find($request, $id), $request->boolean('is_active')));
    }

    public function destroy(Request $request, int $id, DeleteAlertRule $delete): Response
    {
        $delete($request->user(), $this->find($request, $id));

        return response()->noContent();
    }

    private function find(Request $request, int $id): AlertRule
    {
        return $request->user()->alertRules()->with(self::WITH)->find($id) ?? abort(404);
    }
}

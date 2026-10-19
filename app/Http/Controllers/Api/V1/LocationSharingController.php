<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\DisableLocationSharing;
use App\Actions\Locations\EnableLocationSharing;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Exceptions\LocationLimitReached;
use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LocationSharingController extends Controller
{
    /**
     * Turn sharing on for this phone. The only response that ever carries a beacon token.
     */
    public function update(Request $request, EnableLocationSharing $enable): JsonResponse
    {
        try {
            $result = $enable($request->user(), $this->appToken($request));
        } catch (LocationLimitReached) {
            return response()->json([
                'error' => 'location_limit',
                'message' => 'Your plan\'s phone-location allowance is used up. Delete a phone location you no longer use on the dashboard, or upgrade your plan, to share this phone\'s location.',
            ], 422);
        }

        return response()->json(['data' => [
            'location_id' => $result['location']->id,
            'beacon_token' => $result['beacon_token'],
            'url' => route('api.v1.location.store'),
            'interval_seconds' => $result['location']->mobile_update_interval_minutes * 60,
        ]]);
    }

    public function show(Request $request): JsonResponse
    {
        $location = Location::query()
            ->where('user_id', $request->user()->id)
            ->where('app_token_id', $this->appToken($request)->id)
            ->where('type', LocationType::Mobile->value)
            ->where('mobile_source', MobileSource::App->value)
            ->first();

        return response()->json(['data' => [
            'linked' => $location !== null,
            'location_id' => $location?->id,
            'interval_seconds' => $location === null ? null : $location->mobile_update_interval_minutes * 60,
        ]]);
    }

    public function destroy(Request $request, DisableLocationSharing $disable): Response
    {
        $disable($request->user(), $this->appToken($request));

        return response()->noContent();
    }

    private function appToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 403);

        return $token;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\CreateStationaryLocation;
use App\Actions\Locations\DeleteSavedLocation;
use App\Actions\Locations\UpdateSavedLocation;
use App\Exceptions\LocationInUse;
use App\Exceptions\LocationIsAPhone;
use App\Exceptions\LocationLimitReached;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavedLocationRequest;
use App\Http\Requests\UpdateSavedLocationRequest;
use App\Http\Resources\Api\V1\SavedLocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavedLocationController extends Controller
{
    private const NAMED_ALERTS = 5;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return SavedLocationResource::collection($user->locations()->orderBy('label')->orderBy('id')->get())
            ->additional(['meta' => ['can_create' => $user->canCreateLocation(), 'limit' => $user->locationLimit()]]);
    }

    public function show(Request $request, int $id): SavedLocationResource
    {
        return new SavedLocationResource($this->find($request, $id));
    }

    public function store(StoreSavedLocationRequest $request, CreateStationaryLocation $create): JsonResponse
    {
        try {
            $location = $create($request->user(), $request->validated());
        } catch (LocationLimitReached) {
            return response()->json([
                'error' => 'location_limit',
                'message' => 'Your plan\'s location limit is reached. Remove a location, or upgrade, to add another.',
            ], 422);
        }

        return (new SavedLocationResource($location))->response()->setStatusCode(201);
    }

    public function update(UpdateSavedLocationRequest $request, int $id, UpdateSavedLocation $update): SavedLocationResource
    {
        return new SavedLocationResource($update($request->user(), $this->find($request, $id), $request->validated()));
    }

    public function destroy(Request $request, int $id, DeleteSavedLocation $delete): Response|JsonResponse
    {
        $location = $this->find($request, $id);

        try {
            $delete($request->user(), $location);
        } catch (LocationIsAPhone) {
            return response()->json([
                'error' => 'location_is_a_phone',
                'message' => "{$location->label} follows a phone. Stop sharing location on that phone first (Account, in the app).",
            ], 409);
        } catch (LocationInUse $e) {
            $names = collect($e->alertNames);
            $listed = $names->take(self::NAMED_ALERTS)->implode(', ');
            $rest = $names->count() - self::NAMED_ALERTS;

            return response()->json([
                'error' => 'location_in_use',
                'message' => "Can't delete {$location->label}. It's used by: ".($rest > 0 ? "{$listed} (and {$rest} more)" : $listed).'. Remove it from those alerts first.',
                'alerts' => $names->all(),
            ], 409);
        }

        return response()->noContent();
    }

    private function find(Request $request, int $id): Location
    {
        return $request->user()->locations()->find($id) ?? abort(404);
    }
}

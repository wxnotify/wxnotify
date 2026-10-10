<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Weather\SearchUgcCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchZonesRequest;
use Illuminate\Http\JsonResponse;

class ZoneController extends Controller
{
    public function __invoke(SearchZonesRequest $request, SearchUgcCodes $search): JsonResponse
    {
        $limit = (int) $request->validated('limit', 25);

        // One extra row tells whether another page exists, so a page that ends exactly on the last row has no next.
        $rows = $search($request->validated('state'), $request->validated('q'), $limit + 1, $request->validated('after'));
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return response()->json([
            'data' => $rows,
            'meta' => ['next_after' => $hasMore ? $rows[$limit - 1]['code'] : null],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NwsOfficeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $offices = DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'citystate'])
            ->map(fn (object $row): array => ['code' => $row->cwa, 'label' => "{$row->cwa} — {$row->citystate}"])
            ->all();

        return response()->json(['data' => $offices]);
    }
}

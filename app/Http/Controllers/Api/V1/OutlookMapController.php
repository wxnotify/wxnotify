<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OutlookReportRun;
use App\Support\OutlookMapDisk;
use Illuminate\Http\Response;

/**
 * Serves one stored SPC map copy behind a signed link (an image view cannot send a bearer token).
 * Only the stored bytes with the stored mime, never a path.
 */
class OutlookMapController extends Controller
{
    public function show(string $batchKey, string $key): Response
    {
        $run = OutlookReportRun::where('batch_key', $batchKey)->first();
        $map = $run?->mapsExpired() === false ? $run->mapPath($key) : null;

        abort_if($map === null || ! OutlookMapDisk::disk()->exists($map['path']), 404);

        return response(OutlookMapDisk::disk()->get($map['path']), 200, [
            'Content-Type' => $map['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

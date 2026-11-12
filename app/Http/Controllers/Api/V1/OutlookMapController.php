<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OutlookReportRun;
use App\Support\OutlookMapDisk;
use Illuminate\Http\Response;
use Throwable;

/**
 * Serves one stored SPC map copy behind a signed link (an image view cannot send a bearer token).
 * Only the stored bytes with the stored mime, never a path. A disk that cannot be reached, or a read
 * that returns nothing, is a 404 like a missing file; a misnamed disk still fails loudly (resolved
 * outside the guard).
 */
class OutlookMapController extends Controller
{
    public function show(string $batchKey, string $key): Response
    {
        $run = OutlookReportRun::where('batch_key', $batchKey)->first();
        $map = $run?->mapsExpired() === false ? $run->mapPath($key) : null;

        abort_if($map === null, 404);

        $disk = OutlookMapDisk::disk();

        try {
            $bytes = $disk->exists($map['path']) ? $disk->get($map['path']) : null;
        } catch (Throwable) {
            $bytes = null;
        }

        abort_if(! is_string($bytes), 404);

        return response($bytes, 200, [
            'Content-Type' => $map['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

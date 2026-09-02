<?php

namespace App\Http\Controllers;

use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IngestNwwsProductController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $root = realpath(config('services.pipeline.products_path'));
        $absolutePath = $root !== false
            ? realpath($root.DIRECTORY_SEPARATOR.$validated['path'])
            : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $parsed = IngestedProduct::parseFilename(basename($absolutePath));

        if ($parsed['cccc'] === null) {
            Log::warning('Ingested an NWWS product with an unparseable filename', [
                'path' => $validated['path'],
            ]);
        }

        IngestedProduct::updateOrCreate(
            ['file_path' => $validated['path']],
            [
                ...$parsed,
                'text' => file_get_contents($absolutePath),
                'received_at' => now(),
            ],
        );

        return response()->noContent();
    }
}

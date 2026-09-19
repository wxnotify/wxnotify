<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\ExtractWmoHeaderIdentity;
use App\Actions\Pipeline\IngestOncePerWmoHeader;
use App\Actions\Pipeline\IngestProductText;
use App\Models\IngestedProduct;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IngestNwwsProductController extends Controller
{
    public function __construct(
        private readonly IngestProductText $ingestProductText,
        private readonly ExtractWmoHeaderIdentity $extractWmoHeaderIdentity,
        private readonly IngestOncePerWmoHeader $ingestOncePerWmoHeader,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        PipelineHeartbeat::record('nwws');

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

        $text = file_get_contents($absolutePath);
        $isCap = $parsed['awipsid'] !== null && str_starts_with($parsed['awipsid'], 'cap');

        $key = $isCap ? null : (($this->extractWmoHeaderIdentity)($text)['key'] ?? null);

        ($this->ingestOncePerWmoHeader)($key, fn () => ($this->ingestProductText)($text, $validated['path'], $parsed, $isCap, ['source' => 'nwws', 'wmo_header_key' => $key]));

        return response()->noContent();
    }
}

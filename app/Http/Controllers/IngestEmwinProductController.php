<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\ExtractWmoHeaderIdentity;
use App\Actions\Pipeline\IngestOncePerWmoHeader;
use App\Actions\Pipeline\IngestProductText;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IngestEmwinProductController extends Controller
{
    public function __construct(
        private readonly IngestProductText $ingestProductText,
        private readonly ExtractWmoHeaderIdentity $extractWmoHeaderIdentity,
        private readonly IngestOncePerWmoHeader $ingestOncePerWmoHeader,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['path' => ['required', 'string']]);

        PipelineHeartbeat::record('emwin');

        $root = realpath((string) config('services.pipeline.emwin_products_path'));
        $absolutePath = $root !== false ? realpath($root.DIRECTORY_SEPARATOR.$validated['path']) : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $text = file_get_contents($absolutePath);
        $identity = ($this->extractWmoHeaderIdentity)($text);

        ($this->ingestOncePerWmoHeader)($identity['key'] ?? null, fn () => ($this->ingestProductText)(
            $text,
            'emwin/'.$validated['path'],
            [
                'cccc' => $identity['cccc'] ?? null,
                'ttaaii' => $identity['ttaaii'] ?? null,
                'awipsid' => $identity['awipsid'] ?? null,
                'wmo_ddhhmm' => $identity['wmo_ddhhmm'] ?? null,
                'nwws_id' => null,
            ],
            false,
            ['source' => 'emwin', 'wmo_header_key' => $identity['key'] ?? null],
        ));

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Weather\MatchLightningFlashes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IngestGlmFlashesController extends Controller
{
    public function __construct(private readonly MatchLightningFlashes $matchLightningFlashes) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'flashes' => ['present', 'array'],
            'flashes.*.id' => ['required', 'string'],
            'flashes.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'flashes.*.lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        ($this->matchLightningFlashes)($validated['flashes']);

        return response()->noContent();
    }
}

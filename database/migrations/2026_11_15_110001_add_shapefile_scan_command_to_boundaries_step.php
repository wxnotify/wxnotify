<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const FLOW_KEY = 'owner-setup';

    private const OLD_BOUNDARIES = 'WxNotify uses NWS boundary shapefiles (counties, zones and more) to work out which places a warning covers. On Shapefile Layers, press Check for shapefile updates: the scan runs on its own queue worker (queue-long, or any worker started with queue:work database_long --queue=long --timeout=900), so that worker needs to be running; alerts keep being delivered while it runs. Then review each imported layer and press Activate. If a scan fails, press the button again: it re-imports any layer that did not finish.';

    private const NEW_BOUNDARIES = 'WxNotify uses NWS boundary shapefiles (counties, zones and more) to work out which places a warning covers. On Shapefile Layers, press Check for shapefile updates: the scan runs on its own queue worker (queue-long, or any worker started with queue:work database_long --queue=long --timeout=900), so that worker needs to be running; alerts keep being delivered while it runs. The same scan can be run from the command line with php artisan shapefile:scan (add -v to watch it), which imports in that process and needs no queue worker. Then review each imported layer and press Activate. If a scan fails, press the button again: it re-imports any layer that did not finish.';

    /**
     * Names the shapefile:scan command in the boundaries step. An owner's own wording survives: the
     * step changes only while its description still equals the shipped text.
     */
    public function up(): void
    {
        $this->reword(self::OLD_BOUNDARIES, self::NEW_BOUNDARIES);
    }

    public function down(): void
    {
        $this->reword(self::NEW_BOUNDARIES, self::OLD_BOUNDARIES);
    }

    private function reword(string $from, string $to): void
    {
        $flow = OnboardingFlow::query()->where('key', self::FLOW_KEY)->first();

        if ($flow === null) {
            return;
        }

        $step = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'boundaries')->first();

        if ($step === null || ($step->description['en'] ?? null) !== $from) {
            return;
        }

        $step->update(['description' => ['en' => $to]]);
    }
};

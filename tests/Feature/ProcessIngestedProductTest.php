<?php

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\ProcessIngestedProduct;
use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Log;

test('a missing product no-ops and logs a warning instead of throwing', function () {
    Log::spy();
    $matchSpy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matchSpy);
    $syncSpy = Mockery::spy(SyncActiveWwa::class);
    app()->instance(SyncActiveWwa::class, $syncSpy);

    (new ProcessIngestedProduct(999999))->handle();

    $matchSpy->shouldNotHaveReceived('__invoke');
    $syncSpy->shouldNotHaveReceived('__invoke');
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $context['ingested_product_id'] === 999999);
});

test('a replay product is matched even when it carries a vtec_office, and never syncs active_wwas', function () {
    // Mirrors the invariant IngestedProductObserver used to enforce directly
    // (see .ai/rules/pipeline.md): a replay must always go to
    // MatchWeatherAlertRules, never SyncActiveWwa, regardless of vtec_office.
    $matchSpy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matchSpy);
    $syncSpy = Mockery::spy(SyncActiveWwa::class);
    app()->instance(SyncActiveWwa::class, $syncSpy);

    // withoutEvents: these two factory creates would otherwise fire the real
    // IngestedProductObserver themselves (dispatched inline on the sync
    // queue connection tests use), calling into the very spies this test is
    // trying to isolate before the job under test even runs.
    [$original, $replay] = IngestedProduct::withoutEvents(function () {
        $original = IngestedProduct::factory()->create(['vtec_office' => 'KTWC']);

        return [$original, IngestedProduct::factory()->create(['vtec_office' => 'KTWC', 'replay_of_id' => $original->id])];
    });

    (new ProcessIngestedProduct($replay->id))->handle();

    $matchSpy->shouldHaveReceived('__invoke')->once();
    $syncSpy->shouldNotHaveReceived('__invoke');
});

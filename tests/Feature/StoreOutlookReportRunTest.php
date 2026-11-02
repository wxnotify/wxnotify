<?php

use App\Actions\Alerts\StoreOutlookReportRun;
use App\Models\AlertRule;
use App\Models\OutlookReportRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

function storeOutlookRule(): AlertRule
{
    return AlertRule::factory()->outlookReport()->create();
}

it('stores one row with the payload and the rule owner', function () {
    Storage::fake('local');
    $rule = storeOutlookRule();
    $payload = ['days' => [1 => ['available' => true]], 'report_date' => '2026-11-02'];

    $run = app(StoreOutlookReportRun::class)($rule, 'batch-1', $payload, []);

    expect($run->user_id)->toBe($rule->user_id)
        ->and($run->alert_rule_id)->toBe($rule->id)
        ->and($run->batch_key)->toBe('batch-1')
        ->and($run->payload)->toBe($payload)
        ->and(OutlookReportRun::count())->toBe(1);
});

it('writes allowed graphics and records path and mime per key', function () {
    Storage::fake('local');

    $run = app(StoreOutlookReportRun::class)(storeOutlookRule(), 'batch-2', [], [
        'day1' => ['bytes' => 'PNGDATA', 'mime' => 'image/png'],
        'day2' => null,
        'day3' => ['bytes' => '<html>', 'mime' => 'text/html'],
        'day48' => ['bytes' => 'GIFDATA', 'mime' => 'image/gif'],
    ]);

    Storage::disk('local')->assertExists('outlook-maps/batch-2/day1.png');
    Storage::disk('local')->assertExists('outlook-maps/batch-2/day48.gif');
    Storage::disk('local')->assertMissing('outlook-maps/batch-2/day2.png');
    Storage::disk('local')->assertMissing('outlook-maps/batch-2/day3.html');
    expect(Storage::disk('local')->get('outlook-maps/batch-2/day1.png'))->toBe('PNGDATA')
        ->and($run->fresh()->maps)->toBe([
            'day1' => ['path' => 'outlook-maps/batch-2/day1.png', 'mime' => 'image/png'],
            'day48' => ['path' => 'outlook-maps/batch-2/day48.gif', 'mime' => 'image/gif'],
        ]);
});

it('keeps one row and the first bytes when called twice for the same batch', function () {
    Storage::fake('local');
    $rule = storeOutlookRule();
    $action = app(StoreOutlookReportRun::class);

    $action($rule, 'batch-3', [], ['day1' => ['bytes' => 'FIRST', 'mime' => 'image/png']]);
    $run = $action($rule, 'batch-3', [], ['day1' => ['bytes' => 'SECOND', 'mime' => 'image/png']]);

    expect(OutlookReportRun::count())->toBe(1)
        ->and(Storage::disk('local')->get('outlook-maps/batch-3/day1.png'))->toBe('FIRST')
        ->and($run->maps)->toHaveKey('day1');
});

it('saves the run and logs only the exception class when a disk write throws', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andThrow(new RuntimeException('secret/path/leak'));
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    Log::spy();

    $run = app(StoreOutlookReportRun::class)(storeOutlookRule(), 'batch-4', [], [
        'day1' => ['bytes' => 'X', 'mime' => 'image/png'],
    ]);

    expect(OutlookReportRun::count())->toBe(1)
        ->and($run->fresh()->mapPath('day1'))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['exception'] ?? null) === RuntimeException::class
        && ! str_contains(json_encode($context), 'secret'))->once();
});

it('reports expiry from the map retention days', function () {
    expect(OutlookReportRun::factory()->create()->mapsExpired())->toBeFalse()
        ->and(OutlookReportRun::factory()->old()->create()->mapsExpired())->toBeTrue();
});

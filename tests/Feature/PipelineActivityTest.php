<?php

use App\Enums\PipelineFeed;
use App\Models\PipelineDelivery;
use App\Support\Pipeline\PipelineActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => Carbon::setTestNow('2026-09-21 12:30:00'));

afterEach(fn () => Carbon::setTestNow());

test('hourly counts returns 24 buckets oldest first for one feed only', function () {
    PipelineDelivery::factory()->count(2)->create(['received_at' => now()]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(3)]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(23)]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(24)->subMinute()]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['received_at' => now()]);

    $counts = app(PipelineActivity::class)->hourlyCounts(PipelineFeed::Nwws);

    expect($counts)->toHaveCount(24)
        ->and($counts[23])->toBe(2)
        ->and($counts[20])->toBe(1)
        ->and($counts[0])->toBe(1)
        ->and(array_sum($counts))->toBe(4);
});

test('recent returns the newest deliveries for the feed limited to the count', function () {
    foreach (range(1, 10) as $i) {
        PipelineDelivery::factory()->create(['file_name' => "n{$i}.txt", 'received_at' => now()->subMinutes(20 - $i)]);
    }
    PipelineDelivery::factory()->forFeed('emwin')->create(['file_name' => 'other.txt']);

    $recent = app(PipelineActivity::class)->recent(PipelineFeed::Nwws, 8);

    expect($recent)->toHaveCount(8)
        ->and($recent->first()->file_name)->toBe('n10.txt')
        ->and($recent->pluck('file_name'))->not->toContain('other.txt');
});

test('the emwin comparison shows a product delivered by both feeds with both times', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(30)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->duplicate()->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(28)]);

    $rows = app(PipelineActivity::class)->emwinComparison();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('K1')
        ->and($rows[0]['comparable'])->toBeTrue()
        ->and($rows[0]['cells']['emwin']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin_http']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin_http']['at']->equalTo(now()->subMinutes(28)))->toBeTrue();
});

test('a product on one feed is waiting for ten minutes and missing after that', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'young', 'received_at' => now()->subMinutes(9)]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'old', 'received_at' => now()->subMinutes(11)]);

    $rows = app(PipelineActivity::class)->emwinComparison()->keyBy('label');

    expect($rows['YOUNG']['cells']['emwin_http']['state'])->toBe('waiting')
        ->and($rows['OLD']['cells']['emwin_http']['state'])->toBe('missing')
        ->and($rows['OLD']['cells']['emwin']['state'])->toBe('delivered');
});

test('a product without a header key is listed on its own feed and unmatched on the other', function () {
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => null, 'file_name' => 'nohdr.txt']);

    $rows = app(PipelineActivity::class)->emwinComparison();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('nohdr.txt')
        ->and($rows[0]['comparable'])->toBeFalse()
        ->and($rows[0]['cells']['emwin_http']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin']['state'])->toBe('unmatched');
});

test('the emwin comparison is ordered newest first, capped, and ignores nwws', function () {
    foreach (range(1, 12) as $i) {
        PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => "k{$i}", 'received_at' => now()->subMinutes(60 - $i)]);
    }
    PipelineDelivery::factory()->create(['wmo_header_key' => 'nwwsonly']);

    $rows = app(PipelineActivity::class)->emwinComparison(10);

    expect($rows)->toHaveCount(10)
        ->and($rows[0]['label'])->toBe('K12')
        ->and($rows->pluck('label'))->not->toContain('NWWSONLY');
});

test('a second-feed delivery outside the newest window still matches its product', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(40)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(38)]);
    foreach (range(1, 30) as $i) {
        PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => "n{$i}", 'received_at' => now()->subMinutes(20)->addSeconds($i)]);
    }

    $rows = app(PipelineActivity::class)->emwinComparison(40)->keyBy('label');

    expect($rows['K1']['cells']['emwin_http']['state'])->toBe('delivered');
});

test('ingested today counts only ingested deliveries since midnight', function () {
    PipelineDelivery::factory()->count(2)->create(['received_at' => now()]);
    PipelineDelivery::factory()->duplicate()->create(['received_at' => now()]);
    PipelineDelivery::factory()->create(['received_at' => now()->subDay()]);

    expect(app(PipelineActivity::class)->ingestedToday())->toBe(2);
});

test('every query degrades to empty when the table does not exist', function () {
    Schema::drop('pipeline_deliveries');
    $activity = app(PipelineActivity::class);

    expect($activity->hourlyCounts(PipelineFeed::Nwws))->toBe(array_fill(0, 24, 0))
        ->and($activity->recent(PipelineFeed::Nwws))->toHaveCount(0)
        ->and($activity->emwinComparison())->toHaveCount(0)
        ->and($activity->ingestedToday())->toBe(0);
});

test('a real query failure is reported while the queries still return their empty values', function () {
    Exceptions::fake();
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'from "pipeline_deliveries"')) {
            throw new RuntimeException('query failed');
        }
    });
    $activity = app(PipelineActivity::class);

    expect($activity->hourlyCounts(PipelineFeed::Nwws))->toBe(array_fill(0, 24, 0))
        ->and($activity->ingestedToday())->toBe(0)
        ->and($activity->recent(PipelineFeed::Nwws))->toHaveCount(0)
        ->and($activity->emwinComparison())->toHaveCount(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'query failed');
    expect(collect(Exceptions::reported()))->toHaveCount(4);
});

<?php

use App\Actions\Pipeline\RecordPipelineDelivery;
use App\Models\PipelineDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

test('it records a delivery with the file basename only', function () {
    app(RecordPipelineDelivery::class)('emwin_http', 'wfus53 kbou 191622 torbou', 'http/../deep/dir/TORBOU_1.txt', true);

    $row = PipelineDelivery::sole();

    expect($row->feed)->toBe('emwin_http')
        ->and($row->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and($row->file_name)->toBe('TORBOU_1.txt')
        ->and($row->outcome)->toBe('ingested')
        ->and($row->received_at->isToday())->toBeTrue();
});

test('a deduplicated delivery is recorded as a duplicate and a null key is allowed', function () {
    app(RecordPipelineDelivery::class)('emwin', null, 'a.txt', false);

    expect(PipelineDelivery::sole())
        ->outcome->toBe('duplicate')
        ->wmo_header_key->toBeNull();
});

test('a recording failure is swallowed and logged, never thrown', function () {
    Log::spy();
    PipelineDelivery::creating(fn () => throw new RuntimeException('boom'));

    app(RecordPipelineDelivery::class)('nwws', 'k', 'a.txt', true);

    expect(PipelineDelivery::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

test('the prune command deletes deliveries older than the retention and keeps newer ones', function () {
    config(['wxnotify.pipeline.delivery_retention_hours' => 48]);
    $old = PipelineDelivery::factory()->create(['received_at' => now()->subHours(49)]);
    $recent = PipelineDelivery::factory()->create(['received_at' => now()->subHours(47)]);

    Artisan::call('pipeline:prune-deliveries');

    expect(PipelineDelivery::whereKey($old->id)->exists())->toBeFalse()
        ->and(PipelineDelivery::whereKey($recent->id)->exists())->toBeTrue();
});

test('the prune command is scheduled hourly', function () {
    $output = Artisan::output();
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('pipeline:prune-deliveries')
        ->and($output)->toContain('0    * * * *');
});

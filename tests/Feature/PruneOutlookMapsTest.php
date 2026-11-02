<?php

use App\Models\OutlookReportRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

function pruneMapsStoreFiles(OutlookReportRun $run): void
{
    foreach ($run->maps as $map) {
        Storage::disk('local')->put($map['path'], 'x');
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(now());
});

it('deletes the map files and clears maps on a run older than the window, keeping the payload', function () {
    $run = OutlookReportRun::factory()->withMaps()->old()->create();
    pruneMapsStoreFiles($run);

    artisan('outlook:prune-maps')->expectsOutputToContain('1')->assertSuccessful();

    Storage::disk('local')->assertMissing('outlook-maps/'.$run->batch_key.'/day1.png');
    Storage::disk('local')->assertMissing('outlook-maps/'.$run->batch_key.'/day48.gif');
    $run->refresh();
    expect($run->maps)->toBeNull()
        ->and($run->payload['report_date'])->toBe('2026-11-02');
});

it('leaves a run younger than the window untouched', function () {
    $run = OutlookReportRun::factory()->withMaps()->create(['created_at' => now()->subDays(29)]);
    pruneMapsStoreFiles($run);

    artisan('outlook:prune-maps')->assertSuccessful();

    Storage::disk('local')->assertExists('outlook-maps/'.$run->batch_key.'/day1.png');
    expect($run->refresh()->maps)->not->toBeNull();
});

it('skips a run that has no maps and is harmless when run twice', function () {
    $noMaps = OutlookReportRun::factory()->old()->create(['maps' => null]);
    $old = OutlookReportRun::factory()->withMaps()->old()->create();
    pruneMapsStoreFiles($old);

    artisan('outlook:prune-maps')->assertSuccessful();
    artisan('outlook:prune-maps')->expectsOutputToContain('0')->assertSuccessful();

    expect($noMaps->refresh()->maps)->toBeNull()
        ->and($old->refresh()->maps)->toBeNull();
});

it('still clears maps when the directory is already gone', function () {
    $gone = OutlookReportRun::factory()->withMaps()->old()->create();
    $other = OutlookReportRun::factory()->withMaps()->old()->create();
    pruneMapsStoreFiles($other);

    artisan('outlook:prune-maps')->assertSuccessful();

    expect($gone->refresh()->maps)->toBeNull()
        ->and($other->refresh()->maps)->toBeNull();
    Storage::disk('local')->assertMissing('outlook-maps/'.$other->batch_key.'/day1.png');
});

it('keeps maps recorded when a delete fails, so the run is retried, and still prunes the others', function () {
    $failing = OutlookReportRun::factory()->withMaps()->old()->create();
    $other = OutlookReportRun::factory()->withMaps()->old()->create();

    $disk = Mockery::mock();
    $disk->shouldReceive('deleteDirectory')->with('outlook-maps/'.$failing->batch_key)->once()->andReturn(false);
    $disk->shouldReceive('deleteDirectory')->with('outlook-maps/'.$other->batch_key)->once()->andReturn(true);
    $disk->shouldReceive('directories')->with('outlook-maps')->andReturn([]);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    artisan('outlook:prune-maps')->expectsOutputToContain('for 1 outlook report runs')->assertSuccessful();

    expect($failing->refresh()->maps)->not->toBeNull()
        ->and($other->refresh()->maps)->toBeNull();
});

function pruneMapsAgeDirectory(string $directory, int $days): void
{
    foreach (Storage::disk('local')->allFiles($directory) as $file) {
        touch(Storage::disk('local')->path($file), now()->subDays($days)->getTimestamp());
    }
}

it('sweeps a folder with no run once its newest file is as old as the window', function () {
    Storage::disk('local')->put('outlook-maps/orphan/day1.png', 'x');
    Storage::disk('local')->put('outlook-maps/orphan/day48.gif', 'x');
    pruneMapsAgeDirectory('outlook-maps/orphan', 31);

    artisan('outlook:prune-maps')->expectsOutputToContain('Swept 1 orphaned map folders')->assertSuccessful();

    Storage::disk('local')->assertMissing('outlook-maps/orphan/day1.png');
    expect(Storage::disk('local')->directories('outlook-maps'))->toBe([]);
});

it('keeps a folder with no run while any of its files is younger than the window', function () {
    Storage::disk('local')->put('outlook-maps/young/day1.png', 'x');
    Storage::disk('local')->put('outlook-maps/young/day48.gif', 'x');
    touch(Storage::disk('local')->path('outlook-maps/young/day1.png'), now()->subDays(40)->getTimestamp());

    artisan('outlook:prune-maps')->expectsOutputToContain('Swept 0 orphaned map folders')->assertSuccessful();

    Storage::disk('local')->assertExists('outlook-maps/young/day1.png');
});

it('sweeps an empty folder with no run', function () {
    Storage::disk('local')->makeDirectory('outlook-maps/empty');

    artisan('outlook:prune-maps')->expectsOutputToContain('Swept 1 orphaned map folders')->assertSuccessful();

    expect(Storage::disk('local')->directories('outlook-maps'))->toBe([]);
});

it('leaves a folder that still has a run to the row pass, however old', function () {
    $run = OutlookReportRun::factory()->withMaps()->create(['created_at' => now()->subDays(29)]);
    pruneMapsStoreFiles($run);
    pruneMapsAgeDirectory('outlook-maps/'.$run->batch_key, 45);

    artisan('outlook:prune-maps')->expectsOutputToContain('Swept 0 orphaned map folders')->assertSuccessful();

    Storage::disk('local')->assertExists('outlook-maps/'.$run->batch_key.'/day1.png');
    expect($run->refresh()->maps)->not->toBeNull();
});

it('never wipes the map root for a run with a blank batch key', function () {
    Storage::disk('local')->put('outlook-maps/other/day1.png', 'x');
    $blank = OutlookReportRun::factory()->old()->create(['batch_key' => '', 'maps' => ['day1' => ['path' => 'outlook-maps/day1.png', 'mime' => 'image/png']]]);

    artisan('outlook:prune-maps')->expectsOutputToContain('for 0 outlook report runs')->assertSuccessful();

    Storage::disk('local')->assertExists('outlook-maps/other/day1.png');
    expect($blank->refresh()->maps)->not->toBeNull();
});

it('keeps going and reports the rest when an orphaned folder cannot be deleted', function () {
    Storage::disk('local')->put('outlook-maps/a/day1.png', 'x');
    Storage::disk('local')->put('outlook-maps/b/day1.png', 'x');
    pruneMapsAgeDirectory('outlook-maps/a', 40);
    pruneMapsAgeDirectory('outlook-maps/b', 40);

    $real = Storage::disk('local');
    $disk = Mockery::mock($real)->makePartial();
    $disk->shouldReceive('deleteDirectory')->with('outlook-maps/a')->once()->andReturn(false);
    $disk->shouldReceive('deleteDirectory')->with('outlook-maps/b')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    artisan('outlook:prune-maps')->expectsOutputToContain('Swept 1 orphaned map folders')->assertSuccessful();
});

it('is scheduled daily', function () {
    Artisan::call('schedule:list'); // boots the console application, which registers the schedule

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'outlook:prune-maps'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

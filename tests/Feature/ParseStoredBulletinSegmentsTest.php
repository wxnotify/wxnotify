<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use Illuminate\Support\Facades\Queue;

function storedBulletinForSegments(string $example, array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text',
        'vtec_office' => 'KMOB',
        ...$attributes,
    ]));
}

test('rebuilds the segments of the stored VTEC bulletins in the window, and sends nothing', function () {
    Queue::fake();
    $recent = storedBulletinForSegments('MWW_MOB', ['received_at' => now()->subHours(3)]);
    $old = storedBulletinForSegments('NPW_FGZ', ['received_at' => now()->subDays(5)]);
    $afd = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['vtec_office' => null, 'received_at' => now()]));

    $this->artisan('bulletins:parse-segments')
        ->expectsOutputToContain('Rebuilt the segments of 1 bulletin')
        ->assertSuccessful();

    expect($recent->segments()->count())->toBe(3)
        ->and($old->segments()->count())->toBe(0)
        ->and($afd->segments()->count())->toBe(0)
        ->and(DeliveryLog::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('--days widens the window, and running it again leaves the same segments', function () {
    $old = storedBulletinForSegments('NPW_FGZ', ['received_at' => now()->subDays(5)]);

    $this->artisan('bulletins:parse-segments', ['--days' => 7])->assertSuccessful();
    $this->artisan('bulletins:parse-segments', ['--days' => 7])->assertSuccessful();

    expect($old->segments()->count())->toBe(1);
});

/** A bulletin that already has its segments, one of them linked to a delivery. */
function storedBulletinWithLinkedSegments(): array
{
    $bulletin = storedBulletinForSegments('MWW_MOB', ['received_at' => now()->subHours(3)]);
    app(BuildBulletinSegments::class)($bulletin);
    $segmentIds = $bulletin->segments()->orderBy('position')->pluck('id')->all();
    $delivery = DeliveryLog::factory()->create();
    $delivery->segments()->attach($segmentIds[2]);

    return [$bulletin, $segmentIds, $delivery];
}

test('by default a bulletin that already has segments keeps them and their delivery links', function () {
    [$bulletin, $segmentIds, $delivery] = storedBulletinWithLinkedSegments();

    $this->artisan('bulletins:parse-segments')
        ->expectsOutputToContain('Rebuilt the segments of 0 bulletins')
        ->assertSuccessful();

    expect($bulletin->segments()->orderBy('position')->pluck('id')->all())->toBe($segmentIds)
        ->and($delivery->segments()->pluck('ingested_product_segments.id')->all())->toBe([$segmentIds[2]]);
});

test('by default a bulletin with no segments is filled', function () {
    $bulletin = storedBulletinForSegments('MWW_MOB', ['received_at' => now()->subHours(3)]);

    $this->artisan('bulletins:parse-segments')
        ->expectsOutputToContain('Rebuilt the segments of 1 bulletin')
        ->assertSuccessful();

    expect($bulletin->segments()->count())->toBe(3);
});

test('--force rebuilds a bulletin that already has segments and drops their delivery links', function () {
    [$bulletin, $segmentIds, $delivery] = storedBulletinWithLinkedSegments();

    $this->artisan('bulletins:parse-segments', ['--force' => true])
        ->expectsOutputToContain('Rebuilt the segments of 1 bulletin')
        ->assertSuccessful();

    $newIds = $bulletin->segments()->pluck('id')->all();
    expect($newIds)->toHaveCount(3)
        ->and(array_intersect($newIds, $segmentIds))->toBe([])
        ->and($delivery->segments()->count())->toBe(0)
        ->and(IngestedProductSegment::count())->toBe(3);
});

test('--days must be at least 1', function () {
    $this->artisan('bulletins:parse-segments', ['--days' => 0])
        ->expectsOutputToContain('--days must be at least 1')
        ->assertFailed();
});

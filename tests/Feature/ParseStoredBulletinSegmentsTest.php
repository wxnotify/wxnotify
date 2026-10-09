<?php

use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
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

test('--days must be at least 1', function () {
    $this->artisan('bulletins:parse-segments', ['--days' => 0])
        ->expectsOutputToContain('--days must be at least 1')
        ->assertFailed();
});

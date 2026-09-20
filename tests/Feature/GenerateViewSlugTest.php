<?php

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

function slugProduct(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kewx',
        'ttaaii' => 'fous54',
        'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625',
        'received_at' => '2026-09-20 06:25:53',
        'text' => "000\nSome product text\n",
        ...$attributes,
    ]);
}

test('a product gets a header-based slug when it is created', function () {
    expect(slugProduct()->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625');
});

test('a correction marker in the header is appended', function () {
    $product = slugProduct(['text' => "000\nFOUS54 KEWX 200625 CCA\nPFWEWX\nCorrected text\n"]);

    expect($product->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625-cca');
});

test('a product without an AWIPS id leaves that part out', function () {
    $product = slugProduct(['cccc' => 'kncf', 'ttaaii' => 'wous99', 'awipsid' => null]);

    expect($product->fresh()->view_slug)->toBe('kncf-wous99-202609200625');
});

test('a product without a parseable header falls back to received time and row id', function () {
    $product = slugProduct(['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null]);

    expect($product->fresh()->view_slug)->toBe("nws-20260920062553-{$product->id}");
});

test('a header day later than the received day belongs to the previous month', function () {
    $sameYear = slugProduct(['wmo_ddhhmm' => '302355', 'received_at' => '2026-10-01 00:02:10']);
    $acrossYear = slugProduct([
        'awipsid' => 'other',
        'wmo_ddhhmm' => '312355',
        'received_at' => '2027-01-01 00:01:00',
    ]);

    expect($sameYear->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609302355')
        ->and($acrossYear->fresh()->view_slug)->toBe('kewx-fous54-other-202612312355');
});

test('an impossible header date falls back instead of inventing one', function () {
    $product = slugProduct(['wmo_ddhhmm' => '312355', 'received_at' => '2026-10-01 00:02:10']);

    expect($product->fresh()->view_slug)->toBe("nws-20261001000210-{$product->id}");
});

test('a colliding slug gets the row id appended', function () {
    $first = slugProduct();
    $second = slugProduct(['file_path' => 'kewx/second-copy.txt']);

    expect($first->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625')
        ->and($second->fresh()->view_slug)->toBe("kewx-fous54-pfwewx-202609200625-{$second->id}");
});

test('an existing slug is never overwritten on create', function () {
    $product = IngestedProduct::factory()->create(['view_slug' => 'chosen-elsewhere']);

    expect($product->fresh()->view_slug)->toBe('chosen-elsewhere');
});

test('issuedAt returns the derived UTC time, or null when unusable', function () {
    $generator = app(GenerateViewSlug::class);

    expect($generator->issuedAt(slugProduct())?->equalTo(CarbonImmutable::parse('2026-09-20 06:25:00', 'UTC')))->toBeTrue()
        ->and($generator->issuedAt(slugProduct(['awipsid' => 'x', 'wmo_ddhhmm' => null])))->toBeNull()
        ->and($generator->issuedAt(slugProduct(['awipsid' => 'y', 'wmo_ddhhmm' => '202575'])))->toBeNull();
});

test('a slug generation failure is logged and never blocks later created listeners', function () {
    Log::spy();
    app()->bind(GenerateViewSlug::class, fn () => throw new RuntimeException('boom'));
    $laterListenerRan = false;
    IngestedProduct::created(function () use (&$laterListenerRan): void {
        $laterListenerRan = true;
    });

    $product = IngestedProduct::factory()->create();

    expect($product->fresh())->not->toBeNull()
        ->and($product->fresh()->view_slug)->toBeNull()
        ->and($laterListenerRan)->toBeTrue();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'Failed to generate a view slug'
            && $context['id'] === $product->id
            && $context['exception'] === 'boom');
});

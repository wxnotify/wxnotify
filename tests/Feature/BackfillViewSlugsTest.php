<?php

use App\Actions\Pipeline\BackfillViewSlugs;
use App\Models\IngestedProduct;
use Illuminate\Support\Facades\DB;

test('rows without a slug get one, oldest first, and existing slugs are untouched', function () {
    $attributes = [
        'cccc' => 'kewx', 'ttaaii' => 'fous54', 'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625', 'received_at' => '2026-09-20 06:25:53',
    ];
    $first = IngestedProduct::factory()->create($attributes);
    $second = IngestedProduct::factory()->create($attributes);
    $kept = IngestedProduct::factory()->create(['view_slug' => 'already-set']);

    DB::table('ingested_products')->whereIn('id', [$first->id, $second->id])->update(['view_slug' => null]);

    $updated = app(BackfillViewSlugs::class)();

    expect($updated)->toBe(2)
        ->and($first->fresh()->view_slug)->toBe('kewx-fous54-pfwewx-202609200625')
        ->and($second->fresh()->view_slug)->toBe("kewx-fous54-pfwewx-202609200625-{$second->id}")
        ->and($kept->fresh()->view_slug)->toBe('already-set');
});

test('running the backfill again changes nothing', function () {
    $products = IngestedProduct::factory()->count(3)->create();
    DB::table('ingested_products')->whereIn('id', $products->modelKeys())->update(['view_slug' => null]);

    expect(app(BackfillViewSlugs::class)())->toBe(3);
    $slugs = IngestedProduct::query()->orderBy('id')->pluck('view_slug', 'id')->all();

    expect(app(BackfillViewSlugs::class)())->toBe(0)
        ->and(IngestedProduct::query()->orderBy('id')->pluck('view_slug', 'id')->all())->toBe($slugs)
        ->and(array_filter($slugs))->toHaveCount(3);
});

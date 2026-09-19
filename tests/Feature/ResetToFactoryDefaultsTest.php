<?php

use App\Actions\Admin\FactoryReset\WipeSubscriberData;
use App\Actions\Admin\ResetToFactoryDefaults;
use App\Contracts\PipelineStack;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->create(['is_owner' => true]);
});

test('it runs only the selected toggles and logs one audit entry that survives', function () {
    IngestedProduct::factory()->count(2)->create();
    $sub = User::factory()->create();
    Location::factory()->for($sub)->create();

    $result = app(ResetToFactoryDefaults::class)(['pipeline' => true, 'catalogs' => false, 'subscribers' => false], $this->owner, pipelineStopped: true);

    expect(IngestedProduct::count())->toBe(0)
        ->and(Location::count())->toBe(1)
        ->and($result)->toHaveKey('pipeline')->not->toHaveKey('subscribers');

    $entry = Activity::where('description', 'Reset to factory defaults')->sole();
    expect($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->properties['toggles'])->toBe(['pipeline'])
        ->and($entry->properties['deleted']['pipeline']['ingested_products'])->toBe(2);
});

test('the audit log is never wiped, even by every toggle together', function () {
    activity()->log('earlier entry');

    app(ResetToFactoryDefaults::class)(['pipeline' => true, 'catalogs' => true, 'subscribers' => true], $this->owner, pipelineStopped: true);

    expect(Activity::where('description', 'earlier entry')->exists())->toBeTrue()
        ->and(Activity::where('description', 'Reset to factory defaults')->count())->toBe(1);
});

test('nothing selected is rejected', function () {
    app(ResetToFactoryDefaults::class)(['pipeline' => false], $this->owner, pipelineStopped: true);
})->throws(InvalidArgumentException::class);

test('the pipeline toggle needs the stopped acknowledgment while services cannot be stopped', function () {
    IngestedProduct::factory()->create();

    expect(fn () => app(ResetToFactoryDefaults::class)(['pipeline' => true], $this->owner, pipelineStopped: false))
        ->toThrow(DomainException::class)
        ->and(IngestedProduct::count())->toBe(1);
});

test('a stack that can stop is stopped first and needs no acknowledgment', function () {
    $stack = new class implements PipelineStack
    {
        public bool $stopped = false;

        public function canStop(): bool
        {
            return true;
        }

        public function stop(): void
        {
            $this->stopped = true;
        }
    };
    app()->instance(PipelineStack::class, $stack);
    IngestedProduct::factory()->create();

    app(ResetToFactoryDefaults::class)(['pipeline' => true], $this->owner, pipelineStopped: false);

    expect($stack->stopped)->toBeTrue()->and(IngestedProduct::count())->toBe(0);
});

test('a failure in a later toggle rolls back the earlier ones and writes no audit entry', function () {
    IngestedProduct::factory()->create();
    app()->bind(WipeSubscriberData::class, fn () => new class extends WipeSubscriberData
    {
        public function __invoke(): array
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(ResetToFactoryDefaults::class)(['pipeline' => true, 'subscribers' => true], $this->owner, pipelineStopped: true))
        ->toThrow(RuntimeException::class)
        ->and(IngestedProduct::count())->toBe(1)
        ->and(Activity::where('description', 'Reset to factory defaults')->exists())->toBeFalse();
});

test('all three groups together work end to end on the seeded catalog', function () {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(ChannelTypeSeeder::class);
    IngestedProduct::factory()->count(2)->create();
    Location::factory()->for(User::factory()->create())->create();
    Product::factory()->create(['code' => 'ZZZ']);

    $result = app(ResetToFactoryDefaults::class)(['pipeline' => true, 'catalogs' => true, 'subscribers' => true], $this->owner, pipelineStopped: true);

    expect($result)->toHaveKeys(['pipeline', 'catalogs', 'subscribers'])
        ->and($result['catalogs']['custom_products_removed'])->toBeGreaterThanOrEqual(1)
        ->and(Product::where('code', 'ZZZ')->exists())->toBeFalse()
        ->and(IngestedProduct::count())->toBe(0)
        ->and(Location::count())->toBe(0)
        ->and(User::whereKey($this->owner->id)->exists())->toBeTrue();
});

test('a failing audit write is reported but does not make the completed reset fail', function () {
    IngestedProduct::factory()->create();
    Event::listen('eloquent.creating: '.Activity::class, fn () => throw new RuntimeException('audit down'));
    Exceptions::fake();

    $result = app(ResetToFactoryDefaults::class)(['pipeline' => true], $this->owner, pipelineStopped: true);

    expect($result)->toHaveKey('pipeline')->and(IngestedProduct::count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

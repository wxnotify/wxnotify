<?php

use App\Enums\ProductCategory;
use App\Models\DidYouKnow;
use App\Models\Product;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use App\Support\Pipeline\PipelineHeartbeat;
use App\Support\Setup\QueueHeartbeat;
use App\Support\Setup\ReferenceDataStatus;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Wallacemartinss\FilamentOnboarding\Conditions\ConditionDiscovery;
use Wallacemartinss\FilamentOnboarding\Conditions\ConditionRegistry;

/**
 * @return array<string, array{string, Closure, Closure}>
 */
function setupConditionCases(): array
{
    $noMail = fn () => config(['mail.default' => 'log']);

    return [
        'is_owner' => [
            'is_owner',
            fn () => ['is_owner' => true],
            fn () => ['is_owner' => false],
        ],
        'is_cloud_mode' => [
            'is_cloud_mode',
            fn () => config(['wxnotify.mode' => 'cloud']),
            fn () => config(['wxnotify.mode' => 'selfhost']),
        ],
        'feed_product_received' => [
            'feed_product_received',
            fn () => PipelineHeartbeat::record('nwws'),
            fn () => Cache::forget('pipeline.heartbeat.nwws'),
        ],
        'reference_data_installed' => [
            'reference_data_installed',
            function () {
                Product::factory()->create(['category' => ProductCategory::Marine]);
                DidYouKnow::factory()->create();
                foreach (ShapefileLayerDefinitions::all() as $definition) {
                    ShapefileLayer::factory()->create(['key' => $definition->key]);
                }
            },
            fn () => DidYouKnow::factory()->create(),
        ],
        'boundaries_active' => [
            'boundaries_active',
            function () {
                $layer = ShapefileLayer::factory()->create();
                $layer->update(['active_version_id' => ShapefileLayerVersion::factory()->create(['shapefile_layer_id' => $layer->id, 'diff_summary' => []])->id]);
            },
            fn () => ShapefileLayer::factory()->create(),
        ],
        'scheduler_and_queue_running' => [
            'scheduler_and_queue_running',
            fn () => QueueHeartbeat::record(),
            fn () => Cache::put('setup.queue-heartbeat', now()->subMinutes(11)->toIso8601String()),
        ],
        'mail_configured' => [
            'mail_configured',
            fn () => config(['mail.default' => 'smtp']),
            $noMail,
        ],
        'push_configured' => [
            'push_configured',
            function () {
                config([
                    'services.firebase.service_account' => setupConditionTempFile(),
                    'webpush.vapid.public_key' => 'public',
                    'webpush.vapid.private_key' => 'private',
                ]);
            },
            fn () => config([
                'services.firebase.service_account' => setupConditionTempFile(),
                'webpush.vapid.public_key' => 'public',
                'webpush.vapid.private_key' => '',
            ]),
        ],
        'billing_configured' => [
            'billing_configured',
            fn () => config(['services.stripe.secret' => 'sk_test_x', 'services.paddle.api_key' => null]),
            fn () => config(['services.stripe.secret' => null, 'services.paddle.api_key' => '']),
        ],
        'support_widget_configured' => [
            'support_widget_configured',
            fn () => config(['services.chatwoot.base_url' => 'https://chat.example.test', 'services.chatwoot.website_token' => 'token']),
            fn () => config(['services.chatwoot.base_url' => 'https://chat.example.test', 'services.chatwoot.website_token' => null]),
        ],
    ];
}

function setupConditionTempFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'firebase');
    file_put_contents($path, '{}');
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function setupConditionPasses(string $key, array $attributes = []): bool
{
    $user = User::factory()->create($attributes + ['is_owner' => false]);

    return app(ConditionRegistry::class)->passes($key, $user);
}

dataset('setup conditions', fn () => setupConditionCases());

it('passes when its condition holds', function (string $key, Closure $holds, Closure $fails) {
    $attributes = $holds();

    expect(setupConditionPasses($key, is_array($attributes) ? $attributes : []))->toBeTrue();
})->with('setup conditions');

it('does not pass when its condition does not hold', function (string $key, Closure $holds, Closure $fails) {
    $attributes = $fails();

    expect(setupConditionPasses($key, is_array($attributes) ? $attributes : []))->toBeFalse();
})->with('setup conditions');

it('registers every setup condition key with a plain-language label', function (string $key) {
    $registry = app(ConditionRegistry::class);

    expect($registry->has($key))->toBeTrue()
        ->and($registry->label($key))->not->toBe($key);
})->with(array_keys(setupConditionCases()));

it('has no boundaries active when there are no shapefile layers', function () {
    expect(setupConditionPasses('boundaries_active'))->toBeFalse();
});

it('has no boundaries active when one layer of two has no active version', function () {
    $active = ShapefileLayer::factory()->create();
    $active->update(['active_version_id' => ShapefileLayerVersion::factory()->create(['shapefile_layer_id' => $active->id])->id]);
    ShapefileLayer::factory()->create();

    expect(setupConditionPasses('boundaries_active'))->toBeFalse();
});

it('has no boundaries active when the active version never finished importing', function () {
    $layer = ShapefileLayer::factory()->create();
    $layer->update(['active_version_id' => ShapefileLayerVersion::factory()->create(['shapefile_layer_id' => $layer->id, 'diff_summary' => null])->id]);

    expect(setupConditionPasses('boundaries_active'))->toBeFalse();
});

it('counts the scheduler and queue as running for a heartbeat just under ten minutes old', function () {
    Cache::put('setup.queue-heartbeat', now()->subMinutes(9)->toIso8601String());

    expect(setupConditionPasses('scheduler_and_queue_running'))->toBeTrue();
});

it('counts the scheduler and queue as not running with no heartbeat', function () {
    expect(setupConditionPasses('scheduler_and_queue_running'))->toBeFalse();
});

it('answers no, without throwing, when the queue heartbeat holds garbage', function (mixed $garbage) {
    Cache::forever('setup.queue-heartbeat', $garbage);
    Cache::forever('pipeline.heartbeat.nwws', $garbage);

    $user = User::factory()->create();

    foreach (['scheduler_and_queue_running', 'feed_product_received'] as $key) {
        $condition = app(ConditionDiscovery::discover()[$key]);

        expect($condition->isCompleted($user))->toBeFalse();
    }
})->with(['not a date', [['nested']]]);

it('does not count a missing, empty or unreadable firebase file as push configured', function (?string $path) {
    config([
        'services.firebase.service_account' => $path,
        'webpush.vapid.public_key' => 'public',
        'webpush.vapid.private_key' => 'private',
    ]);

    expect(setupConditionPasses('push_configured'))->toBeFalse();
})->with([null, '', '/nonexistent/firebase.json']);

it('does not count the array mailer as mail configured', function () {
    config(['mail.default' => 'array']);

    expect(setupConditionPasses('mail_configured'))->toBeFalse();
});

it('accepts a paddle key as billing configured', function () {
    config(['services.stripe.secret' => null, 'services.paddle.api_key' => 'pdl_x']);

    expect(setupConditionPasses('billing_configured'))->toBeTrue();
});

it('checks that reference data is installed with a handful of cheap queries', function () {
    Product::factory()->create(['category' => ProductCategory::Marine]);
    DidYouKnow::factory()->create();

    DB::enableQueryLog();
    $installed = ReferenceDataStatus::allInstalled();
    $queries = count(DB::getQueryLog());

    expect($installed)->toBeFalse()
        ->and($queries)->toBeLessThanOrEqual(3);
});

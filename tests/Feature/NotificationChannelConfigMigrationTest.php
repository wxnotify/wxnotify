<?php

use App\Models\NotificationChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A fresh instance of the migration under test. `require` (not `require_once`)
 * re-evaluates the file, so each call returns a new anonymous-class instance.
 */
function chanConfigMigration(): Migration
{
    return require database_path('migrations/2026_09_20_100002_add_config_to_notification_channels_and_make_apprise_url_nullable.php');
}

test('rolling back refuses to run while config-only channels exist and deletes nothing', function () {
    $webhook = NotificationChannel::factory()->webhook()->create();
    $webPush = NotificationChannel::factory()->webPush()->create();

    expect(fn () => chanConfigMigration()->down())
        ->toThrow(RuntimeException::class);

    expect(NotificationChannel::whereIn('id', [$webhook->id, $webPush->id])->count())->toBe(2)
        ->and(Schema::hasColumn('notification_channels', 'config'))->toBeTrue()
        ->and($webhook->fresh()->config['secret'])->toBe('test-secret');
});

test('the rollback refusal names how many channels are in the way', function () {
    NotificationChannel::factory()->webhook()->create();

    $thrown = null;

    try {
        chanConfigMigration()->down();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('1')->toContain('config-only');
});

test('rolling back succeeds and restores the original schema when no config-only channels exist', function () {
    NotificationChannel::factory()->create();

    chanConfigMigration()->down();

    $appriseUrl = collect(Schema::getColumns('notification_channels'))->firstWhere('name', 'apprise_url');

    expect(Schema::hasColumn('notification_channels', 'config'))->toBeFalse()
        ->and($appriseUrl['nullable'])->toBeFalse();
});

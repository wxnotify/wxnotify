<?php

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\FindAppAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function loadDeliveryLogReadAtMigration(): object
{
    return require base_path('database/migrations/2026_10_20_100001_add_read_at_to_delivery_logs_table.php');
}

test('an alert is unread while any push row is unread and read when all are read', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $email = $group->create(['channel_type' => 'email']);
    $group->pushed()->read()->create();
    $unreadPush = $group->pushed()->create();

    $alert = (new FindAppAlert)($user, $email->id);

    expect($alert->isUnread())->toBeTrue()
        ->and($alert->pushRows()->pluck('id')->all())->toHaveCount(2)->not->toContain($email->id);

    $unreadPush->forceFill(['read_at' => now()])->save();

    expect((new FindAppAlert)($user, $email->id)->isUnread())->toBeFalse();
});

test('a failed push or another channel never makes an alert unread', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $read = $group->pushed()->read()->create();
    $group->pushed()->failed()->create();
    $group->create(['channel_type' => 'email']);

    expect((new FindAppAlert)($user, $read->id)->isUnread())->toBeFalse()
        ->and((new CountUnreadAppAlerts)($user))->toBe(0);
});

test('a new push row after read makes the group unread again', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $first = $group->pushed()->read()->create();

    expect((new CountUnreadAppAlerts)($user))->toBe(0);

    $group->pushed()->create();

    expect((new FindAppAlert)($user, $first->id)->isUnread())->toBeTrue()
        ->and((new CountUnreadAppAlerts)($user))->toBe(1);
});

test('CountUnreadAppAlerts counts groups not rows, per user, and ignores other users', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();

    DeliveryLog::factory()->for($user)->for($rule)->for(IngestedProduct::factory())->pushed()->count(3)->create();
    DeliveryLog::factory()->for($user)->for($rule)->for(IngestedProduct::factory())->pushed()->create();
    DeliveryLog::factory()->for($user)->for($rule)->for(IngestedProduct::factory())->pushed()->read()->create();
    DeliveryLog::factory()->for($user)->pushed()->count(2)->create(['ingested_product_id' => null]);
    DeliveryLog::factory()->for($user)->for($rule)->for(IngestedProduct::factory())->create(['channel_type' => 'email']);
    DeliveryLog::factory()->for(User::factory())->for(IngestedProduct::factory())->pushed()->create();

    expect((new CountUnreadAppAlerts)($user))->toBe(4);
});

test('the migration backfill marks existing push rows read in batches', function () {
    $this->freezeTime();
    $createdAt = now()->subDays(2)->startOfSecond();
    $pushes = DeliveryLog::factory()->pushed()->count(5)->create(['created_at' => $createdAt]);
    $alreadyRead = DeliveryLog::factory()->pushed()->create(['read_at' => now()->subDay()->startOfSecond()]);
    $failed = DeliveryLog::factory()->pushed()->failed()->create();
    $email = DeliveryLog::factory()->create(['channel_type' => 'email']);

    DB::enableQueryLog();

    loadDeliveryLogReadAtMigration()->backfill(chunkSize: 2);

    $updates = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'update'));

    expect($updates->count())->toBeGreaterThan(1)
        ->and(DeliveryLog::whereKey($pushes->modelKeys())->pluck('read_at')->map->toDateTimeString()->unique()->all())->toBe([$createdAt->toDateTimeString()])
        ->and($alreadyRead->fresh()->read_at->toDateTimeString())->toBe(now()->subDay()->startOfSecond()->toDateTimeString())
        ->and($failed->fresh()->read_at)->toBeNull()
        ->and($email->fresh()->read_at)->toBeNull();
});

/**
 * Runs the callback outside the test's wrapping transaction, because CREATE/DROP INDEX CONCURRENTLY refuses to
 * run inside one (as on a real deploy, the migration is not transactional). The callback must leave the schema
 * as migrated and commit no rows; the test transaction is reopened afterwards.
 */
function outsideTestTransaction(Closure $callback): void
{
    DB::commit();

    try {
        $callback();
    } finally {
        DB::beginTransaction();
    }
}

function unreadPushIndexIsValid(): bool
{
    return (bool) DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'delivery_logs_unread_push_index'::regclass")?->indisvalid;
}

test('the read-state migration can be run again after an interruption and leaves a valid index', function () {
    outsideTestTransaction(function () {
        $migration = loadDeliveryLogReadAtMigration();

        $migration->down();
        $migration->up();

        // Interrupted after the column was committed (the migration never got recorded): run it again.
        $migration->up();

        // Interrupted before the index was built: the column is there, the index is missing.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_unread_push_index');
        $migration->up();
    });

    expect(unreadPushIndexIsValid())->toBeTrue();
});

test('an index left invalid by an interrupted concurrent build is rebuilt', function () {
    outsideTestTransaction(function () {
        // Mark the index invalid, as a failed CREATE INDEX CONCURRENTLY leaves it.
        DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'delivery_logs_unread_push_index'::regclass");

        loadDeliveryLogReadAtMigration()->up();
    });

    expect(unreadPushIndexIsValid())->toBeTrue();
});

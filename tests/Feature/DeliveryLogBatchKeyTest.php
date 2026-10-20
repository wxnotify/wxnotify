<?php

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\FindAppAlert;
use App\Actions\Alerts\PushBadgeCount;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
});

/**
 * Two phones that received one scheduled run of a rule: one successful push row per phone, sharing a batch key.
 *
 * @return array{0: DeliveryLog, 1: DeliveryLog}
 */
function batchKeyTwoPhonePushes(User $user, AlertRule $rule, string $batchKey): array
{
    $group = DeliveryLog::factory()->for($user)->for($rule)->pushed();

    return [
        $group->create(['batch_key' => $batchKey, 'ingested_product_id' => null]),
        $group->create(['batch_key' => $batchKey, 'ingested_product_id' => null]),
    ];
}

function batchKeyAppToken(User $user): string
{
    return $user->createToken('iPhone', ['app'])->plainTextToken;
}

function loadDeliveryLogBatchKeyMigration(): object
{
    return require base_path('database/migrations/2026_10_21_100001_add_batch_key_to_delivery_logs_table.php');
}

test('a summary pushed to two phones is one feed entry with the lowest row id and one unread', function () {
    $user = User::factory()->create();
    [$first, $second] = batchKeyTwoPhonePushes($user, AlertRule::factory()->for($user)->create(), 'batch-a');

    $this->withToken(batchKeyAppToken($user))->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread_count', 1);

    expect($second->id)->toBeGreaterThan($first->id);
});

test('reading a batch alert marks both phones\' rows read', function () {
    $user = User::factory()->create();
    [$first, $second] = batchKeyTwoPhonePushes($user, AlertRule::factory()->for($user)->create(), 'batch-a');

    $this->withToken(batchKeyAppToken($user))->postJson("/api/v1/alerts/{$first->id}/read")
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 0]]);

    expect(DeliveryLog::whereKey([$first->id, $second->id])->whereNull('read_at')->count())->toBe(0);
});

test('mark all reads a batch alert and counts it once before', function () {
    $user = User::factory()->create();
    [$first, $second] = batchKeyTwoPhonePushes($user, AlertRule::factory()->for($user)->create(), 'batch-a');

    expect((new CountUnreadAppAlerts)($user))->toBe(1);

    $this->withToken(batchKeyAppToken($user))->postJson('/api/v1/alerts/read-all')
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 0]]);

    expect(DeliveryLog::whereKey([$first->id, $second->id])->whereNull('read_at')->count())->toBe(0);
});

test('no-product rows without a batch key stay one alert each, as before', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();
    DeliveryLog::factory()->for($user)->for($rule)->pushed()->count(2)->create(['ingested_product_id' => null]);

    $this->withToken(batchKeyAppToken($user))->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.unread_count', 2);
});

test('two runs of one rule and two rules at the same time are separate alerts', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();
    batchKeyTwoPhonePushes($user, $rule, 'run-1');
    batchKeyTwoPhonePushes($user, $rule, 'run-2');
    batchKeyTwoPhonePushes($user, AlertRule::factory()->for($user)->create(), 'other-rule');

    $this->withToken(batchKeyAppToken($user))->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.unread_count', 3);
});

test('a batch alert is found by any of its rows\' ids, but not by another user', function () {
    $user = User::factory()->create();
    [$first, $second] = batchKeyTwoPhonePushes($user, AlertRule::factory()->for($user)->create(), 'batch-a');
    $email = DeliveryLog::factory()->for($user)->create(['batch_key' => 'batch-a', 'channel_type' => 'email']);
    $stranger = User::factory()->create();
    batchKeyTwoPhonePushes($stranger, AlertRule::factory()->for($stranger)->create(), 'batch-a');

    $bySecond = (new FindAppAlert)($user, $second->id);

    expect($bySecond->id())->toBe($first->id)
        ->and($bySecond->rows->modelKeys())->toBe([$first->id, $second->id, $email->id])
        ->and((new FindAppAlert)($user, $email->id)->id())->toBe($first->id)
        ->and((new FindAppAlert)($stranger, $first->id))->toBeNull();

    $this->withToken(batchKeyAppToken($stranger))->getJson("/api/v1/alerts/{$first->id}")->assertNotFound();
});

test('a push in a batch already unread on another phone does not add one to the badge', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();
    DeliveryLog::factory()->for($user)->for($rule)->pushed()->create(['batch_key' => 'batch-a', 'ingested_product_id' => null]);

    expect((new PushBadgeCount)($user, $rule->id, null, true, 'batch-a'))->toBe(1)
        ->and((new PushBadgeCount)($user, $rule->id, null, true, 'batch-b'))->toBe(2)
        ->and((new PushBadgeCount)($user, $rule->id, null, true))->toBe(2);
});

test('a push in a batch whose rows are read, failed or someone else\'s counts as new', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();
    $batch = DeliveryLog::factory()->for($rule)->state(['batch_key' => 'batch-a', 'ingested_product_id' => null]);
    $batch->for($user)->pushed()->read()->create();
    $batch->for($user)->pushed()->failed()->create();
    $batch->for(User::factory())->pushed()->create();

    expect((new PushBadgeCount)($user, $rule->id, null, true, 'batch-a'))->toBe(1);
});

/**
 * Runs the callback outside the test's wrapping transaction (CREATE/DROP INDEX CONCURRENTLY refuses to run inside
 * one), then reopens it. The callback must leave the schema as migrated and commit no rows.
 */
function batchKeyOutsideTestTransaction(Closure $callback): void
{
    DB::commit();

    try {
        $callback();
    } finally {
        DB::beginTransaction();
    }
}

function batchKeyIndexIsValid(): bool
{
    return (bool) DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'delivery_logs_batch_key_index'::regclass")?->indisvalid;
}

test('the batch-key migration can be run again after an interruption and leaves a valid index', function () {
    batchKeyOutsideTestTransaction(function () {
        $migration = loadDeliveryLogBatchKeyMigration();

        $migration->down();
        $migration->up();

        // Interrupted after the column was committed (the migration never got recorded): run it again.
        $migration->up();

        // Interrupted before the index was built: the column is there, the index is missing.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_batch_key_index');
        $migration->up();
    });

    expect(batchKeyIndexIsValid())->toBeTrue();
});

test('a batch-key index left invalid by an interrupted concurrent build is rebuilt', function () {
    batchKeyOutsideTestTransaction(function () {
        DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'delivery_logs_batch_key_index'::regclass");

        loadDeliveryLogBatchKeyMigration()->up();
    });

    expect(batchKeyIndexIsValid())->toBeTrue();
});

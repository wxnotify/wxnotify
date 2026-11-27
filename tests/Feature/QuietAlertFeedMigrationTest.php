<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These tests commit and leave the test's wrapping transaction, so this file must never create rows (no
 * data-creating beforeEach): anything written before the commit would stay in the testing database.
 */

function loadAppUnreadIndexMigration(): object
{
    $path = collect(glob(base_path('database/migrations/*_replace_delivery_logs_unread_push_index.php')))->sole();

    return require $path;
}

/**
 * Null when the index does not exist, else whether PostgreSQL considers it usable.
 */
function deliveryLogIndexValidity(string $index): ?bool
{
    $row = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index]);

    return $row === null ? null : (bool) $row->indisvalid;
}

/**
 * CREATE/DROP INDEX CONCURRENTLY refuses to run inside a transaction (and a migration needs its own), so this
 * leaves the test's wrapping one. The callback must leave the schema as migrated and commit no rows.
 */
function outsideFeedTestTransaction(Closure $callback): void
{
    DB::commit();

    try {
        $callback();
    } finally {
        DB::beginTransaction();
    }
}

test('migrating leaves only the new unread index, valid', function () {
    expect(deliveryLogIndexValidity('delivery_logs_app_unread_index'))->toBeTrue()
        ->and(deliveryLogIndexValidity('delivery_logs_unread_push_index'))->toBeNull();
});

test('the index migration can be run again and rebuilds an index left invalid', function () {
    outsideFeedTestTransaction(function () {
        loadAppUnreadIndexMigration()->up();

        // As an interrupted CREATE INDEX CONCURRENTLY leaves it.
        DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'delivery_logs_app_unread_index'::regclass");
        loadAppUnreadIndexMigration()->up();
    });

    expect(deliveryLogIndexValidity('delivery_logs_app_unread_index'))->toBeTrue()
        ->and(deliveryLogIndexValidity('delivery_logs_unread_push_index'))->toBeNull();
});

test('rolling the index migration back restores the old index', function () {
    $rolledBack = [];

    outsideFeedTestTransaction(function () use (&$rolledBack) {
        try {
            loadAppUnreadIndexMigration()->down();
            $rolledBack = [deliveryLogIndexValidity('delivery_logs_unread_push_index'), deliveryLogIndexValidity('delivery_logs_app_unread_index')];
        } finally {
            loadAppUnreadIndexMigration()->up();
        }
    });

    expect($rolledBack)->toBe([true, null]);
});

test('the in_app column migration gives up on a held lock instead of queueing, and runs on the next try', function () {
    $name = basename(collect(glob(base_path('database/migrations/*_add_in_app_to_delivery_logs_table.php')))->sole(), '.php');
    // The real migrator, so the migration's own transaction (which SET LOCAL needs) is the one a deploy gets.
    $migrate = fn () => Artisan::call('migrate', ['--path' => "database/migrations/{$name}.php", '--force' => true]);
    $result = [];

    outsideFeedTestTransaction(function () use ($name, $migrate, &$result) {
        config(['database.connections.lock_holder' => config('database.connections.'.DB::getDefaultConnection())]);
        $holder = DB::connection('lock_holder');

        try {
            // Dropping the column also drops the unread index; both are restored below.
            (require base_path("database/migrations/{$name}.php"))->down();
            DB::table('migrations')->where('migration', $name)->delete();

            // What any long-running query on the table holds; ALTER TABLE has to wait for it.
            $holder->beginTransaction();
            $holder->statement('LOCK TABLE delivery_logs IN ACCESS SHARE MODE');

            // A safety net only: without a lock timeout the migration would wait here for ever.
            DB::statement("SET statement_timeout = '15s'");
            $startedAt = microtime(true);

            try {
                $migrate();
            } catch (QueryException $exception) {
                $result['sqlstate'] = $exception->getCode();
            }

            $result['seconds'] = microtime(true) - $startedAt;
            $result['column_after_timeout'] = Schema::hasColumn('delivery_logs', 'in_app');
            $result['recorded_after_timeout'] = DB::table('migrations')->where('migration', $name)->exists();

            $holder->rollBack();
            $migrate();

            $result['column_after_retry'] = Schema::hasColumn('delivery_logs', 'in_app');
            $result['recorded_after_retry'] = DB::table('migrations')->where('migration', $name)->exists();
            // SET LOCAL ends with the migration's transaction.
            $result['lock_timeout_afterwards'] = DB::selectOne('SHOW lock_timeout')->lock_timeout;
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }

            DB::purge('lock_holder');
            DB::statement('RESET statement_timeout');

            if (! Schema::hasColumn('delivery_logs', 'in_app')) {
                $migrate();
            }

            loadAppUnreadIndexMigration()->up();
        }
    });

    // 55P03 is lock_not_available; the safety net would be 57014.
    expect($result['sqlstate'] ?? null)->toBe('55P03')
        ->and($result['seconds'])->toBeGreaterThan(2.5)->toBeLessThan(10.0)
        ->and($result['column_after_timeout'])->toBeFalse()
        ->and($result['recorded_after_timeout'])->toBeFalse()
        ->and($result['column_after_retry'])->toBeTrue()
        ->and($result['recorded_after_retry'])->toBeTrue()
        ->and($result['lock_timeout_afterwards'])->toBe('0')
        ->and(deliveryLogIndexValidity('delivery_logs_app_unread_index'))->toBeTrue();
});

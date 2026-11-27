<?php

use App\Actions\Alerts\CountUnreadAppAlerts;
use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Alerts\GetAlertStats;
use App\Actions\Alerts\ListAppAlerts;
use App\Actions\Alerts\PushBadgeCount;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\AlertRef;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->rule = AlertRule::factory()->for($this->user)->create();
    $this->product = IngestedProduct::factory()->create();

    // A phone alert held by the rule's quiet time; `in_app` is what puts it in the app's feed.
    $this->heldRow = fn (array $overrides = []) => DeliveryLog::factory()->for($this->user)->create([
        'alert_rule_id' => $this->rule->id,
        'ingested_product_id' => $this->product->id,
        'category' => 'weather_alerts',
        'subject' => 'Tornado Warning',
        'channel_type' => 'mobile_push',
        'status' => 'suppressed',
        'in_app' => true,
        ...$overrides,
    ]);

    $this->ref = fn (): string => AlertRef::forGroup($this->rule->id, $this->product->id, null);

    $this->api = function (string $method, string $uri, array $data = []) {
        // The guard caches the first request's user for the rest of the test.
        app('auth')->forgetGuards();

        return $this->withToken($this->token)->json($method, $uri, $data);
    };
});

test('a row is not marked for the app unless something marks it', function () {
    $row = DeliveryLog::factory()->for($this->user)->create(['channel_type' => 'mobile_push', 'status' => 'suppressed']);

    expect($row->fresh()->in_app)->toBeFalse();
});

test('a marked held phone alert is in the app feed, unread, and reads as held', function () {
    $row = ($this->heldRow)();

    ($this->api)('GET', '/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $row->id)
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.channels.0.status', 'suppressed')
        ->assertJsonPath('data.0.has_failure', false)
        ->assertJsonPath('meta.unread_count', 1);

    foreach (["/api/v1/alerts/{$row->id}", '/api/v1/alerts/by-ref/'.($this->ref)()] as $uri) {
        ($this->api)('GET', $uri)
            ->assertOk()
            ->assertJsonPath('data.id', $row->id)
            ->assertJsonPath('data.read', false)
            ->assertJsonCount(1, 'data.channels')
            ->assertJsonPath('data.channels.0.status', 'suppressed')
            ->assertJsonPath('data.has_failure', false);
    }
});

test('a held row that is unmarked, or not a phone row, stays out of the app', function (string $channelType, bool $inApp) {
    $row = ($this->heldRow)(['channel_type' => $channelType, 'in_app' => $inApp]);

    ($this->api)('GET', '/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
    ($this->api)('GET', "/api/v1/alerts/{$row->id}")->assertNotFound();
    ($this->api)('GET', '/api/v1/alerts/by-ref/'.($this->ref)())->assertNotFound();
    ($this->api)('POST', "/api/v1/alerts/{$row->id}/read")->assertNotFound();
    ($this->api)('POST', '/api/v1/alerts/read-ref', ['ref' => ($this->ref)()])->assertOk();
    ($this->api)('POST', '/api/v1/alerts/read-all')->assertOk();

    expect($row->fresh()->read_at)->toBeNull()
        ->and((new CountUnreadAppAlerts)($this->user))->toBe(0)
        ->and((new PushBadgeCount)($this->user, null, null, false))->toBe(0)
        // The group is not unread, so a push to it adds one.
        ->and((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe(1);
})->with([
    'held before this change (unmarked phone row)' => ['mobile_push', false],
    'a marked email row' => ['email', true],
    'a marked web push row' => ['web_push', true],
]);

test('a marked held alert counts as unread, and its group adds nothing to the badge again', function () {
    ($this->heldRow)();
    ($this->heldRow)();

    expect((new CountUnreadAppAlerts)($this->user))->toBe(1)
        ->and((new PushBadgeCount)($this->user, null, null, false))->toBe(1)
        ->and((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe(1)
        ->and((new PushBadgeCount)($this->user, $this->rule->id, IngestedProduct::factory()->create()->id, true))->toBe(2);
});

test('reading a marked held alert stamps it', function (string $how) {
    $row = ($this->heldRow)(['created_at' => now()->subMinute()]);

    $response = match ($how) {
        'by id' => ($this->api)('POST', "/api/v1/alerts/{$row->id}/read"),
        'by ref' => ($this->api)('POST', '/api/v1/alerts/read-ref', ['ref' => ($this->ref)()]),
        'all' => ($this->api)('POST', '/api/v1/alerts/read-all'),
    };

    $response->assertOk()->assertExactJson(['data' => ['unread_count' => 0]]);

    expect($row->fresh()->read_at)->not->toBeNull()
        ->and($row->fresh()->status)->toBe('suppressed');
})->with(['by id', 'by ref', 'all']);

test('a newer marked row makes a group that was read unread again', function () {
    $delivered = ($this->heldRow)(['status' => 'success', 'in_app' => false, 'read_at' => now(), 'dispatched_at' => now()->subHour()]);
    ($this->heldRow)();

    ($this->api)('GET', '/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $delivered->id)
        ->assertJsonPath('data.0.read', false)
        ->assertJsonCount(2, 'data.0.channels')
        ->assertJsonPath('meta.unread_count', 1);
});

test('statistics still count a marked alert as held, not delivered', function () {
    ($this->heldRow)();

    $stats = (new GetAlertStats)($this->user);

    expect($stats['held'])->toBe(1)
        ->and($stats['deliveries'])->toBe(0)
        ->and($stats['failures'])->toBe(0);
});

test('active now still reports a marked alert as held', function () {
    Location::factory()->for($this->user)->create([
        'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
    $vtec = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_year' => (int) now()->year];
    $wwa = ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'],
        'expires' => now()->addHour(),
        ...$vtec,
    ]);
    // Quietly: the observer would queue ProcessIngestedProduct and re-sync (overwrite) the active_wwas row.
    $bulletin = IngestedProduct::factory()->createQuietly([...$vtec, 'vtec_event_number' => $wwa->vtec_event_number, 'is_test' => false]);
    ($this->heldRow)(['ingested_product_id' => $bulletin->id]);

    $events = (new GetActiveNowAtMyLocations)($this->user)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('held')
        ->and($events[0]['alerted_via'])->toBe([]);
});

test('the model, the scope and the grouped feed SQL agree on what is in the feed', function (string $channelType, string $status, bool $inApp) {
    $row = ($this->heldRow)(['channel_type' => $channelType, 'status' => $status, 'in_app' => $inApp])->fresh();
    $expected = $channelType === 'mobile_push' && ($status === 'success' || $inApp);
    $grouped = DeliveryLog::query()->whereKey($row->id)->selectRaw(ListAppAlerts::HAS_PUSH.' AS has_push')->value('has_push');

    expect($row->isInAppFeed())->toBe($expected)
        ->and(DeliveryLog::query()->whereKey($row->id)->inAppFeed()->exists())->toBe($expected)
        ->and((bool) $grouped)->toBe($expected);
})->with(['mobile_push', 'email', 'web_push'])->with(['success', 'suppressed', 'failure', 'skipped'])->with([true, false]);

test('the feed scope stays inside its own parentheses when combined with other conditions', function () {
    ($this->heldRow)();
    $other = User::factory()->create();

    expect(DeliveryLog::query()->where('user_id', $other->id)->inAppFeed()->count())->toBe(0);
});

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
 * CREATE/DROP INDEX CONCURRENTLY refuses to run inside a transaction, so this leaves the test's wrapping one.
 * The callback must leave the schema as migrated and commit no rows.
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

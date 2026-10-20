<?php

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\AppAlertCursor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->product = IngestedProduct::factory()->create();
    $this->rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);

    $this->log = fn (array $overrides = []) => DeliveryLog::factory()->for($this->user)->create([
        'alert_rule_id' => $this->rule->id,
        'ingested_product_id' => $this->product->id,
        'category' => 'weather_alerts',
        'subject' => 'Tornado Warning',
        'channel_type' => 'mobile_push',
        'status' => 'success',
        ...$overrides,
    ]);
});

test('deliveries of one rule and product collapse into one alert listing its push channel only', function () {
    ($this->log)(['channel_type' => 'discord', 'dispatched_at' => now()->subMinutes(5)]);
    ($this->log)(['channel_type' => 'pushover', 'dispatched_at' => now()->subMinutes(4), 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);
    ($this->log)(['dispatched_at' => now()->subMinutes(3)]);

    $response = $this->withToken($this->token)->getJson('/api/v1/alerts')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Tornado Warning')
        ->assertJsonPath('data.0.category', 'weather_alerts')
        ->assertJsonPath('data.0.category_label', 'Weather Alerts')
        ->assertJsonPath('data.0.has_failure', false)
        ->assertJsonCount(1, 'data.0.channels')
        ->assertJsonPath('data.0.channels.0', ['type' => 'mobile_push', 'type_label' => 'Mobile app', 'status' => 'success'])
        ->assertJsonPath('meta.next_cursor', null);

    expect($response->getContent())->not->toContain('SECRET-DETAIL');
});

test('the alert id is the lowest delivery id and its time the latest', function () {
    $first = ($this->log)(['dispatched_at' => now()->subMinutes(5)]);
    ($this->log)(['dispatched_at' => now()->subMinutes(1)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.dispatched_at', now()->subMinutes(1)->toIso8601String());
});

test('deliveries with no product stay separate alerts', function () {
    ($this->log)(['ingested_product_id' => null, 'category' => 'daily_summary', 'subject' => 'Daily summary 1', 'dispatched_at' => now()->subMinutes(3)]);
    ($this->log)(['ingested_product_id' => null, 'category' => 'daily_summary', 'subject' => 'Daily summary 2', 'dispatched_at' => now()->subMinutes(2)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.subject', 'Daily summary 2');
});

test('a different rule on the same product is its own alert', function () {
    $other = AlertRule::factory()->for($this->user)->create();
    ($this->log)(['dispatched_at' => now()->subMinutes(3)]);
    ($this->log)(['alert_rule_id' => $other->id, 'dispatched_at' => now()->subMinutes(2)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')->assertJsonCount(2, 'data');
});

test('alerts are newest first and paginate by cursor without gaps or repeats, even with equal times', function () {
    $same = now()->subMinutes(10)->startOfSecond();
    $ids = collect(range(1, 5))->map(fn ($i) => ($this->log)([
        'ingested_product_id' => IngestedProduct::factory()->create()->id,
        'subject' => "Alert {$i}",
        'dispatched_at' => $i <= 3 ? $same : now()->subMinutes(10 - $i),
    ])->id);

    $seen = [];
    $cursor = null;

    do {
        $page = $this->withToken($this->token)->getJson('/api/v1/alerts?limit=2'.($cursor ? '&before='.$cursor : ''))->assertOk();
        $seen = [...$seen, ...collect($page->json('data'))->pluck('id')->all()];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5)
        ->and($seen[0])->toBe($ids[4])
        ->and($seen[1])->toBe($ids[3]);
});

test('limit is validated and an unreadable cursor is rejected', function (string $query) {
    $this->withToken($this->token)->getJson('/api/v1/alerts?'.$query)->assertStatus(422);
})->with([
    'limit 0' => ['limit=0'],
    'limit 51' => ['limit=51'],
    'bad cursor' => ['before=not-a-cursor'],
    'impossible date' => ['before='.AppAlertCursor::encode('2026-02-30 10:00:00', 1)],
    'relative date' => ['before='.AppAlertCursor::encode('+1 week', 1)],
]);

test('only the subscriber\'s own alerts are listed', function () {
    ($this->log)();
    DeliveryLog::factory()->for(User::factory()->create())->pushed()->create(['subject' => 'Not mine']);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['subject' => 'Not mine']);
});

test('an older row without a subject falls back to the rule name, then to Alert', function () {
    ($this->log)(['subject' => null, 'dispatched_at' => now()->subMinutes(2)]);
    ($this->log)(['subject' => null, 'alert_rule_id' => null, 'ingested_product_id' => null, 'dispatched_at' => now()->subMinute()]);

    $data = $this->withToken($this->token)->getJson('/api/v1/alerts')->json('data');

    expect($data[0]['subject'])->toBe('Alert')->and($data[1]['subject'])->toBe('Storm warnings');
});

test('the detail adds the rule, the channel labels and the product page, and never an error detail', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'mobile_push', 'label' => 'Team Discord']);
    $log = ($this->log)(['channel_type' => 'discord', 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);
    ($this->log)(['notification_channel_id' => $channel->id, 'dispatched_at' => now()->addSecond()]);

    $response = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk();

    $response->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.rule_name', 'Storm warnings')
        ->assertJsonPath('data.product_url', $this->product->viewUrl())
        ->assertJsonCount(1, 'data.channels')
        ->assertJsonPath('data.channels.0.label', 'Team Discord')
        ->assertJsonPath('data.channels.0.status', 'success')
        ->assertJsonPath('data.has_failure', false);

    expect($response->getContent())->not->toContain('SECRET-DETAIL');
});

test('the detail tolerates a deleted rule and channel', function () {
    $log = ($this->log)(['alert_rule_id' => null, 'notification_channel_id' => null, 'ingested_product_id' => null]);

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.rule_name', null)
        ->assertJsonPath('data.product_url', null)
        ->assertJsonPath('data.channels.0.label', null);
});

test('another subscriber\'s alert and an unknown id are not found', function () {
    $theirs = DeliveryLog::factory()->for(User::factory()->create())->pushed()->create();

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$theirs->id}")->assertNotFound();
    $this->withToken($this->token)->getJson('/api/v1/alerts/999999')->assertNotFound();
});

test('the tier does not matter', function () {
    ($this->log)();

    $this->withToken($this->token)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');
});

test('listing the feed carries each alert\'s product page without loading the products\' text column', function () {
    ($this->log)();
    ($this->log)(['ingested_product_id' => null, 'category' => 'daily_summary', 'subject' => 'Daily summary']);

    DB::enableQueryLog();

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.product_url', $this->product->viewUrl())
        ->assertJsonPath('data.0.product_url', null);

    $productQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "ingested_products"'));

    expect($productQueries)->toHaveCount(1)
        ->and($productQueries->first()['query'])->toContain('select "id", "view_slug" from "ingested_products"');
});

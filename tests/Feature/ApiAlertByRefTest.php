<?php

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;
use App\Support\AlertRef;

function byRefBatchKey(): string
{
    return '0b9f6f5e-3c1d-4a2b-9e8f-1a2b3c4d5e6f';
}

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->rule = AlertRule::factory()->for($this->user)->create(['name' => 'Severe outlook']);

    $this->batchRow = fn (array $overrides = []) => DeliveryLog::factory()->for($this->user)->create([
        'alert_rule_id' => $this->rule->id,
        'ingested_product_id' => null,
        'batch_key' => byRefBatchKey(),
        'category' => 'outlook_report',
        'subject' => 'Severe Wx Outlook - 🟠 D1',
        'channel_type' => 'mobile_push',
        'status' => 'success',
        ...$overrides,
    ]);
});

function byRefGet($test, string $ref, ?string $token = null)
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->getJson('/api/v1/alerts/by-ref/'.$ref);
}

test('a batch reference answers with the alert detail, keyed by the group\'s lowest row id', function () {
    $email = ($this->batchRow)(['channel_type' => 'email']);
    ($this->batchRow)();

    byRefGet($this, AlertRef::forGroup($this->rule->id, null, byRefBatchKey()))
        ->assertOk()
        ->assertJsonPath('data.id', $email->id)
        ->assertJsonPath('data.subject', 'Severe Wx Outlook - 🟠 D1')
        ->assertJsonPath('data.category', 'outlook_report')
        ->assertJsonPath('data.rule_name', 'Severe outlook')
        ->assertJsonCount(1, 'data.channels');
});

test('a product reference answers with the alert for that rule and product', function () {
    $product = IngestedProduct::factory()->create();
    $row = ($this->batchRow)(['ingested_product_id' => $product->id, 'batch_key' => null, 'category' => 'weather_alerts']);

    byRefGet($this, 'p.'.$this->rule->id.'.'.$product->id)
        ->assertOk()
        ->assertJsonPath('data.id', $row->id);
});

test('a product reference for a rule-less alert answers, and one under another rule or product is a 404', function () {
    $product = IngestedProduct::factory()->create();
    $row = ($this->batchRow)(['ingested_product_id' => $product->id, 'batch_key' => null, 'alert_rule_id' => null, 'category' => 'weather_alerts']);
    $otherRule = AlertRule::factory()->for($this->user)->create();

    byRefGet($this, 'p.0.'.$product->id)->assertOk()->assertJsonPath('data.id', $row->id);
    byRefGet($this, 'p.'.$otherRule->id.'.'.$product->id)->assertNotFound();
    byRefGet($this, 'p.0.'.($product->id + 1000))->assertNotFound();
});

test('a reference that is malformed, unknown or whose push has not been delivered is a 404', function (string $ref) {
    byRefGet($this, $ref)->assertNotFound();
})->with([
    'not a reference' => 'nonsense',
    'a batch key that is not a uuid' => 'b.not-a-uuid',
    'a product reference whose product is zero' => 'p.0.0',
    'a batch with nothing written' => 'b.11111111-2222-3333-4444-555555555555',
]);

test('a group with no successful push row is a 404', function () {
    ($this->batchRow)(['channel_type' => 'email']);
    ($this->batchRow)(['status' => 'failure', 'error_detail' => 'Connection timed out.']);

    byRefGet($this, AlertRef::forGroup($this->rule->id, null, byRefBatchKey()))->assertNotFound();
});

test('another subscriber\'s reference is a 404 and nothing of it leaks', function () {
    ($this->batchRow)();
    $other = User::factory()->create();
    $otherToken = $other->createToken('iPhone', ['app'])->plainTextToken;

    byRefGet($this, 'b.'.byRefBatchKey(), $otherToken)->assertNotFound()->assertJsonMissing(['subject' => 'Severe Wx Outlook - 🟠 D1']);
});

test('the lookup needs an app token', function () {
    ($this->batchRow)();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/alerts/by-ref/b.'.byRefBatchKey())->assertUnauthorized();

    $location = $this->user->createToken('beacon', ['location'])->plainTextToken;

    byRefGet($this, 'b.'.byRefBatchKey(), $location)->assertForbidden();
});

<?php

use App\Actions\Alerts\PushBadgeCount;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->rule = AlertRule::factory()->for($this->user)->create();
    $this->product = IngestedProduct::factory()->create();

    DeliveryLog::factory()->for($this->user)->for($this->rule)->for(IngestedProduct::factory())->pushed()->create();
});

test('a push for a new alert counts itself', function () {
    expect((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe(2);
});

test('a push for an already-unread alert does not add one', function () {
    DeliveryLog::factory()->for($this->user)->for($this->rule)->for($this->product)->pushed()->create();

    expect((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe(2);
});

test('a push for a read alert counts it unread again', function () {
    DeliveryLog::factory()->for($this->user)->for($this->rule)->for($this->product)->pushed()->read()->create();

    expect((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe(2);
});

test('only an unread successful push of the same user, rule and product makes the group already unread', function (Closure $makeRow, int $expected) {
    $makeRow($this);

    expect((new PushBadgeCount)($this->user, $this->rule->id, $this->product->id, true))->toBe($expected);
})->with([
    'a failed push' => [fn ($test) => DeliveryLog::factory()->for($test->user)->for($test->rule)->for($test->product)->pushed()->failed()->create(), 2],
    'an email row' => [fn ($test) => DeliveryLog::factory()->for($test->user)->for($test->rule)->for($test->product)->create(['channel_type' => 'email']), 2],
    'another rule (its own unread alert)' => [fn ($test) => DeliveryLog::factory()->for($test->user)->for(AlertRule::factory()->for($test->user))->for($test->product)->pushed()->create(), 3],
    'another user' => [fn ($test) => DeliveryLog::factory()->for(User::factory())->for($test->rule)->for($test->product)->pushed()->create(), 2],
]);

test('a push without a rule matches only the unread group without a rule', function () {
    DeliveryLog::factory()->for($this->user)->for($this->product)->pushed()->create(['alert_rule_id' => null]);

    expect((new PushBadgeCount)($this->user, null, $this->product->id, true))->toBe(2);
});

test('a daily summary (no product) always counts as new', function () {
    DeliveryLog::factory()->for($this->user)->for($this->rule)->pushed()->create(['ingested_product_id' => null]);

    expect((new PushBadgeCount)($this->user, $this->rule->id, null, true))->toBe(3);
});

test('a push that is not an alert carries the plain unread count', function () {
    expect((new PushBadgeCount)($this->user, null, null, false))->toBe(1);
});

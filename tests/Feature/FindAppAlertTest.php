<?php

use App\Actions\Alerts\FindAppAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;

test('FindAppAlert is null for a group without a successful push row', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $email = $group->create(['channel_type' => 'email']);
    $group->pushed()->failed()->create();
    $standalone = DeliveryLog::factory()->for($user)->create(['channel_type' => 'email', 'ingested_product_id' => null]);

    expect((new FindAppAlert)($user, $email->id))->toBeNull()
        ->and((new FindAppAlert)($user, $standalone->id))->toBeNull();
});

test('FindAppAlert returns the whole group by its lowest id once a push succeeded', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $email = $group->create(['channel_type' => 'email']);
    $group->pushed()->create();

    $alert = (new FindAppAlert)($user, $email->id);

    expect($alert->id())->toBe($email->id)
        ->and($alert->rows)->toHaveCount(2);
});

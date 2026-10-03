<?php

use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\NotificationChannels\NotificationChannelResource;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\TraccarServer;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
});

$resources = [
    'My Alerts' => [AlertRuleResource::class, fn (User $user) => AlertRule::factory()->for($user)->create()],
    'Notification Channels' => [NotificationChannelResource::class, fn (User $user) => NotificationChannel::factory()->for($user)->create()],
    'Traccar Servers' => [TraccarServerResource::class, fn (User $user) => TraccarServer::factory()->for($user)->create()],
    'Alert Templates' => [AlertTemplateResource::class, fn (User $user) => AlertTemplate::factory()->for($user)->create()],
    'My Locations' => [LocationResource::class, fn (User $user) => Location::factory()->for($user)->create()],
];

test('the sidebar badge counts only the signed-in subscriber\'s own rows', function (string $resource, Closure $create) {
    $subscriber = User::factory()->create();
    $someoneElse = User::factory()->create();

    $create($subscriber);
    $create($subscriber);
    $create($someoneElse);
    $create($someoneElse);
    $create($someoneElse);

    $this->actingAs($subscriber);

    expect($resource::getNavigationBadge())->toBe('2');
})->with($resources);

test('a subscriber with no rows sees a zero badge even when others have some', function (string $resource, Closure $create) {
    $create(User::factory()->create());

    $this->actingAs(User::factory()->create());

    expect($resource::getNavigationBadge())->toBe('0');
})->with($resources);

<?php

use App\Enums\ChannelTransport;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\MobilePushChannelType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

test('the mobile app type is a free, non-slot, app-created channel hidden from the picker', function () {
    $type = app(ChannelTypeRegistry::class)->find('mobile_push');

    expect($type)->toBeInstanceOf(MobilePushChannelType::class)
        ->and($type->label())->toBe('Mobile app')
        ->and($type->transport())->toBe(ChannelTransport::MobilePush)
        ->and($type->countsTowardFreeSlot())->toBeFalse()
        ->and($type->supportsInlineCreate())->toBeFalse()
        ->and($type->supportsRichContent())->toBeFalse()
        ->and($type->isAvailable())->toBeFalse()
        ->and($type->destinationLabel())->toBe('Mobile device')
        ->and(app(ChannelTypeRegistry::class)->forPicker()->map(fn ($row) => $row['type']->slug())->all())->not->toContain('mobile_push');
});

test('the catalog row ships for free subscribers', function () {
    expect(DB::table('channel_types')->where('slug', 'mobile_push')->first())
        ->min_tier->toBe('free')->enabled->toBeTrue()->sort_order->toBe(10);
});

test('a device channel cannot be built by hand', function () {
    app(ChannelTypeRegistry::class)->find('mobile_push')->buildDestination([]);
})->throws(LogicException::class);

test('the destination shows only the platform, never the token', function (string $platform, string $summary) {
    $channel = NotificationChannel::factory()->mobilePush('super-secret-fcm-token', $platform)->make();

    $shown = app(ChannelTypeRegistry::class)->find('mobile_push')->displaySummary($channel);

    expect($shown)->toBe($summary)->and($shown)->not->toContain('super-secret');
})->with([
    'android' => ['android', 'Android device'],
    'ios' => ['ios', 'iPhone'],
    'unknown' => ['plan9', '—'],
]);

test('the channels table lists a device with its caption and offers Send Test', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->mobilePush(platform: 'android')->create(['label' => 'Pixel 9']);

    $this->actingAs($user);

    livewire(ListNotificationChannels::class)
        ->assertCanSeeTableRecords([$channel])
        ->assertSee('Pixel 9')
        ->assertSee('Android device')
        ->assertSee('Mobile device')
        ->assertTableActionVisible('sendTest', $channel);
});

test('a device channel can be renamed and the rename touches nothing else', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->mobilePush('keep-this-token')->create(['label' => 'Pixel 9']);
    $this->actingAs($user);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), ['label' => 'Work phone'])
        ->assertHasNoFormErrors();

    expect($channel->fresh())
        ->label->toBe('Work phone')
        ->config->toBe(['fcm_token' => 'keep-this-token', 'platform' => 'android']);
});

test('removing the access token removes the device channel and its rule links', function () {
    $user = User::factory()->create();
    $accessToken = $user->createToken('Pixel 9', ['app'])->accessToken;
    $channel = NotificationChannel::factory()->for($user)->mobilePush()->create(['personal_access_token_id' => $accessToken->id]);
    $rule = AlertRule::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel->id);

    $accessToken->delete();

    expect(NotificationChannel::find($channel->id))->toBeNull()
        ->and(DB::table('alert_rule_notification_channel')->where('notification_channel_id', $channel->id)->count())->toBe(0);
});

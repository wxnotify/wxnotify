<?php

use App\Enums\Tier;
use App\Models\ChannelType as ChannelTypeModel;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('Tier::meets orders free below plus below pro', function () {
    expect(Tier::Free->meets(Tier::Free))->toBeTrue()
        ->and(Tier::Free->meets(Tier::Plus))->toBeFalse()
        ->and(Tier::Free->meets(Tier::Pro))->toBeFalse()
        ->and(Tier::Plus->meets(Tier::Free))->toBeTrue()
        ->and(Tier::Plus->meets(Tier::Plus))->toBeTrue()
        ->and(Tier::Plus->meets(Tier::Pro))->toBeFalse()
        ->and(Tier::Pro->meets(Tier::Plus))->toBeTrue()
        ->and(Tier::Pro->meets(Tier::Pro))->toBeTrue();
});

test('a Free subscriber cannot add a plus-tier type even though it does not count toward the free slot', function () {
    ChannelTypeModel::query()->where('slug', 'pushover')->update(['min_tier' => Tier::Plus]);

    $free = User::factory()->create(['tier_override' => Tier::Free]);
    $plus = User::factory()->create(['tier_override' => Tier::Plus]);
    $pro = User::factory()->create(['tier_override' => Tier::Pro]);

    expect($free->meetsChannelTier('pushover'))->toBeFalse()
        ->and($free->canAddChannel('pushover'))->toBeFalse()
        ->and($plus->meetsChannelTier('pushover'))->toBeTrue()
        ->and($plus->canAddChannel('pushover'))->toBeTrue()
        ->and($pro->canAddChannel('pushover'))->toBeTrue();
});

test('a plus-tier type that never uses the free slot is still closed to Free', function () {
    ChannelTypeModel::query()->where('slug', 'email')->update(['min_tier' => Tier::Plus]);
    $free = User::factory()->create(['tier_override' => Tier::Free]);

    expect($free->canAddChannel('email'))->toBeFalse();
});

test('a type with no catalog row counts as free', function () {
    DB::table('channel_types')->where('slug', 'pushover')->delete();
    $free = User::factory()->create(['tier_override' => Tier::Free]);

    expect($free->meetsChannelTier('pushover'))->toBeTrue()
        ->and($free->canAddChannel('pushover'))->toBeTrue();
});

test('the shipped free types are unchanged for a Free subscriber', function () {
    $free = User::factory()->create(['tier_override' => Tier::Free]);

    expect($free->canAddChannel('discord'))->toBeTrue()
        ->and($free->canAddChannel('email'))->toBeTrue();

    NotificationChannel::factory()->for($free)->create(['channel_type' => 'discord']);

    expect($free->fresh()->canAddChannel('slack'))->toBeFalse()
        ->and($free->fresh()->canAddChannel('email'))->toBeTrue();
});

test('the SMS catalog rows ship plus-tier and switched off, and the helper switches them on', function () {
    $rows = fn () => ChannelTypeModel::query()->whereIn('slug', ['twilio_sms', 'bulkvs_sms', 'httpsms'])->orderBy('sort_order')->get();

    expect($rows()->map->only(['slug', 'min_tier', 'sort_order', 'enabled'])->all())->toBe([
        ['slug' => 'twilio_sms', 'min_tier' => Tier::Plus, 'sort_order' => 11, 'enabled' => false],
        ['slug' => 'bulkvs_sms', 'min_tier' => Tier::Plus, 'sort_order' => 12, 'enabled' => false],
        ['slug' => 'httpsms', 'min_tier' => Tier::Plus, 'sort_order' => 13, 'enabled' => false],
    ]);

    chanEnablePhoneTypes();

    expect($rows()->pluck('enabled')->all())->toBe([true, true, true]);
});

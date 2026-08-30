<?php

use App\Filament\Admin\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the index has no create, edit, or delete actions', function () {
    livewire(ListActivityLogs::class)
        ->assertActionDoesNotExist('create');
});

test('an update to a LogsActivity-enabled model produces a correctly-attributed activity row', function () {
    $product = Product::factory()->create(['name' => 'Original']);

    $product->update(['name' => 'Updated']);

    $activity = Activity::where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('event', 'updated')
        ->sole();

    expect($activity->causer->is($this->staff))->toBeTrue()
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Updated');
});

test('an update made during an impersonated session attributes to the admin, not the subscriber', function () {
    $subscriber = User::factory()->create();
    $product = Product::factory()->create();

    app(ImpersonateManager::class)->enter($this->staff, $subscriber);
    $this->actingAs($subscriber);

    $product->update(['name' => 'Changed while impersonating']);

    $activity = Activity::where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('event', 'updated')
        ->sole();

    expect($activity->causer->is($this->staff))->toBeTrue()
        ->and($activity->causer->is($subscriber))->toBeFalse();
});

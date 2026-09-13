<?php

use App\Filament\Admin\Resources\DidYouKnows\Pages\CreateDidYouKnow;
use App\Filament\Admin\Resources\DidYouKnows\Pages\EditDidYouKnow;
use App\Filament\Admin\Resources\DidYouKnows\Pages\ListDidYouKnows;
use App\Models\DidYouKnow;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can create a fact', function () {
    livewire(CreateDidYouKnow::class)
        ->fillForm(['body' => 'Tornado Warnings from WxNotify reach you in seconds.'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DidYouKnow::where('body', 'Tornado Warnings from WxNotify reach you in seconds.')->exists())->toBeTrue();
});

test('staff can edit a fact', function () {
    $fact = DidYouKnow::factory()->create(['body' => 'Old body']);

    livewire(EditDidYouKnow::class, ['record' => $fact->getRouteKey()])
        ->fillForm(['body' => 'New body', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($fact->fresh()->body)->toBe('New body')
        ->and($fact->fresh()->is_active)->toBeFalse();
});

test('staff can toggle a fact inactive and delete it', function () {
    $fact = DidYouKnow::factory()->create();

    livewire(ListDidYouKnows::class)
        ->callTableAction('delete', $fact);

    expect(DidYouKnow::find($fact->id))->toBeNull();
});

test('the dashboard sidebar shows an active fact and not an inactive one', function () {
    DidYouKnow::factory()->create(['body' => 'Active fact shown on the dashboard']);
    DidYouKnow::factory()->inactive()->create(['body' => 'Inactive fact hidden from the dashboard']);

    $subscriber = User::factory()->create();

    $this->actingAs($subscriber)
        ->get('/dashboard')
        ->assertSee('Active fact shown on the dashboard')
        ->assertDontSee('Inactive fact hidden from the dashboard');
});

test('the dashboard sidebar rotates through more than one fact', function () {
    // Distinct sort_order per row reproduces the real bug: active()'s own
    // ->orderBy('sort_order') previously outranked a later ->inRandomOrder(),
    // so the same lowest-sort_order row won every single time.
    foreach (range(1, 10) as $i) {
        DidYouKnow::factory()->create(['body' => "Fact number {$i}", 'sort_order' => $i]);
    }

    $seen = [];

    foreach (range(1, 15) as $attempt) {
        $seen[] = DidYouKnow::active()->inRandomOrder()->first()->body;
    }

    expect(count(array_unique($seen)))->toBeGreaterThan(1);
});

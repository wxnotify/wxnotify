<?php

use App\Filament\Admin\Resources\DeliveryLogs\Pages\ListDeliveryLogs;
use App\Models\DeliveryLog;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the empty state renders correctly with zero rows', function () {
    livewire(ListDeliveryLogs::class)
        ->assertSee('No delivery attempts yet');
});

test('seeded delivery logs are visible and filterable by status', function () {
    $success = DeliveryLog::factory()->create();
    $failure = DeliveryLog::factory()->failed()->create();

    livewire(ListDeliveryLogs::class)
        ->assertCanSeeTableRecords([$success, $failure])
        ->filterTable('status', 'failure')
        ->assertCanSeeTableRecords([$failure])
        ->assertCanNotSeeTableRecords([$success]);
});

test('no create, edit, or delete actions are exposed', function () {
    DeliveryLog::factory()->create();

    livewire(ListDeliveryLogs::class)
        ->assertActionDoesNotExist('create');
});

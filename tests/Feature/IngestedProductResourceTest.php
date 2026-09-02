<?php

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Models\IngestedProduct;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can list ingested products', function () {
    $product = IngestedProduct::factory()->create(['cccc' => 'kkci']);

    livewire(ListIngestedProducts::class)
        ->assertCanSeeTableRecords([$product]);
});

test('the cccc filter narrows results to the selected office', function () {
    $kkci = IngestedProduct::factory()->create(['cccc' => 'kkci']);
    $ktae = IngestedProduct::factory()->create(['cccc' => 'ktae']);

    livewire(ListIngestedProducts::class)
        ->filterTable('cccc', 'kkci')
        ->assertCanSeeTableRecords([$kkci])
        ->assertCanNotSeeTableRecords([$ktae]);
});

test('the received_at range filter narrows results to the selected window', function () {
    $inRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(2)]);
    $outOfRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(10)]);

    livewire(ListIngestedProducts::class)
        ->filterTable('received_at', ['from' => now()->subDays(5)->toDateString()])
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

test('there are no create, edit, or delete actions', function () {
    IngestedProduct::factory()->create();

    livewire(ListIngestedProducts::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

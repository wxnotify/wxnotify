<?php

use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
});

test('the sidebar lists the ungrouped Dashboard first, then Live Weather, Alerting and Support (Tools stays after Alerting when visible)', function () {
    $this->actingAs(User::factory()->create());

    $navigation = collect(Filament::getNavigation());

    expect($navigation->map(fn ($group) => $group->getLabel())->values()->all())
        ->toBe([null, 'Live Weather', 'Alerting', 'Support'])
        ->and($navigation->first()->getItems()->map(fn ($item) => $item->getLabel())->all())->toBe(['Dashboard']);
});

test('Map sorts before Products inside the Live Weather group', function () {
    $this->actingAs(User::factory()->create());

    $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Live Weather');

    expect($group->getItems()->map(fn ($item) => $item->getLabel())->values()->all())->toBe(['Map', 'Products']);
});

test('the panel registers every group in the order Live Weather, Alerting, Tools, Support', function () {
    expect(array_values(Filament::getCurrentPanel()->getNavigationGroups()))
        ->toBe(['Live Weather', 'Alerting', 'Tools', 'Support']);
});

<?php

use App\Filament\Dashboard\Pages\SignedInDevices;
use App\Filament\Dashboard\Widgets\BrowserSessionsTable;
use App\Models\User;
use App\Models\UserSession;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->current = UserSession::factory()->for($this->user)->create(['id' => session()->getId()]);
});

test('the page shows the browser sessions section beside the app devices', function () {
    $this->get(SignedInDevices::getUrl())
        ->assertSuccessful()
        ->assertSeeLivewire(BrowserSessionsTable::class);
});

test('only the subscriber\'s own browsers are listed', function () {
    $mine = UserSession::factory()->for($this->user)->create();
    $theirs = UserSession::factory()->for(User::factory())->create();
    $signedOut = UserSession::factory()->create(['user_id' => null]);

    livewire(BrowserSessionsTable::class)
        ->assertCanSeeTableRecords([$this->current, $mine])
        ->assertCanNotSeeTableRecords([$theirs, $signedOut]);
});

test('this browser is marked and cannot be signed out from the list', function () {
    livewire(BrowserSessionsTable::class)
        ->assertSee('This browser')
        ->assertTableActionHidden('signOut', $this->current);
});

test('signing out one browser deletes only that session and is logged', function () {
    $other = UserSession::factory()->for($this->user)->create(['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0']);
    $untouched = UserSession::factory()->for($this->user)->create();

    livewire(BrowserSessionsTable::class)
        ->callAction(TestAction::make('signOut')->table($other))
        ->assertNotified();

    expect(UserSession::find($other->id))->toBeNull()
        ->and(UserSession::find($untouched->id))->not->toBeNull()
        ->and(UserSession::find($this->current->id))->not->toBeNull();

    $entry = Activity::where('event', 'revoked')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->properties->get('browser'))->toBe('Firefox on Linux')
        ->and(json_encode($entry->toArray()))->not->toContain($other->id);
});

test('signing out all other browsers keeps this one and every other subscriber\'s sessions', function () {
    $a = UserSession::factory()->for($this->user)->create();
    $b = UserSession::factory()->for($this->user)->create();
    $theirs = UserSession::factory()->for(User::factory())->create();

    livewire(BrowserSessionsTable::class)
        ->callAction(TestAction::make('signOutOthers')->table())
        ->assertNotified();

    expect(UserSession::find($a->id))->toBeNull()
        ->and(UserSession::find($b->id))->toBeNull()
        ->and(UserSession::find($this->current->id))->not->toBeNull()
        ->and(UserSession::find($theirs->id))->not->toBeNull()
        ->and(Activity::where('event', 'revoked')->sole()->properties->get('count'))->toBe(2);
});

test('another subscriber\'s browser cannot be signed out', function () {
    $theirs = UserSession::factory()->for(User::factory())->create();

    expect(fn () => livewire(BrowserSessionsTable::class)->callAction(TestAction::make('signOut')->table($theirs)))
        ->toThrow(ActionNotResolvableException::class);

    expect(UserSession::find($theirs->id))->not->toBeNull();
});

test('signing out is blocked while staff impersonate the subscriber', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));
    $other = UserSession::factory()->for($this->user)->create();

    livewire(BrowserSessionsTable::class)
        ->callAction(TestAction::make('signOut')->table($other))
        ->callAction(TestAction::make('signOutOthers')->table());

    expect(UserSession::find($other->id))->not->toBeNull()
        ->and(Activity::where('event', 'revoked')->exists())->toBeFalse();
});

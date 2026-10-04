<?php

use App\Filament\Dashboard\Pages\SignedInDevices;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('only the subscriber\'s own app devices are listed', function () {
    $mine = $this->user->createToken('iPhone 16', ['app'])->accessToken;
    $apiKey = $this->user->createToken('My script', ['api'])->accessToken;
    $theirs = User::factory()->create()->createToken('Their phone', ['app'])->accessToken;

    livewire(SignedInDevices::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$apiKey, $theirs]);
});

test('revoking deletes the token and is logged without it', function () {
    $new = $this->user->createToken('iPhone 16', ['app']);

    livewire(SignedInDevices::class)
        ->callAction(TestAction::make('revoke')->table($new->accessToken))
        ->assertNotified();

    expect(PersonalAccessToken::find($new->accessToken->id))->toBeNull();

    $entry = Activity::where('event', 'revoked')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->properties->get('device'))->toBe('iPhone 16')
        ->and(json_encode($entry->toArray()))->not->toContain(explode('|', $new->plainTextToken)[1]);
});

test('another subscriber\'s token cannot be revoked', function () {
    $theirs = User::factory()->create()->createToken('Their phone', ['app'])->accessToken;

    // The record is outside the table's query, so Filament cannot even resolve the action for it.
    expect(fn () => livewire(SignedInDevices::class)->callAction(TestAction::make('revoke')->table($theirs)))
        ->toThrow(ActionNotResolvableException::class);

    expect(PersonalAccessToken::find($theirs->id))->not->toBeNull()
        ->and(Activity::where('event', 'revoked')->exists())->toBeFalse();
});

test('revoking is blocked while staff impersonate the subscriber', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));
    $token = $this->user->createToken('iPhone 16', ['app'])->accessToken;

    livewire(SignedInDevices::class)->callAction(TestAction::make('revoke')->table($token));

    expect(PersonalAccessToken::find($token->id))->not->toBeNull()
        ->and(Activity::where('event', 'revoked')->exists())->toBeFalse();
});

test('a revoked device can no longer use the API', function () {
    $new = $this->user->createToken('iPhone 16', ['app']);
    $this->withToken($new->plainTextToken)->getJson('/api/v1/me')->assertOk();

    livewire(SignedInDevices::class)->callAction(TestAction::make('revoke')->table($new->accessToken));

    $this->app['auth']->forgetGuards();
    $this->withToken($new->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

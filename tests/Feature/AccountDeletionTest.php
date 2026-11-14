<?php

use App\Actions\Account\CancelAccountDeletion;
use App\Actions\Account\RequestAccountDeletion;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Mail\AccountDeletionScheduledMail;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Delivery\ChannelDispatcher;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('requesting deletion schedules it, signs the account out everywhere and sends the notice', function () {
    $this->user->createToken('iPhone', ['app']);
    $this->user->createToken('My script', ['api']);
    UserSession::factory()->for($this->user)->count(2)->create();
    $other = User::factory()->create();
    $otherSession = UserSession::factory()->for($other)->create();

    app(RequestAccountDeletion::class)($this->user);

    $this->user->refresh();

    expect($this->user->isPendingDeletion())->toBeTrue()
        ->and($this->user->tokens()->count())->toBe(0)
        ->and(UserSession::where('user_id', $this->user->id)->count())->toBe(0)
        ->and(UserSession::find($otherSession->id))->not->toBeNull()
        ->and($this->user->deletionDate()->isSameDay(now()->addDays(7)))->toBeTrue();

    Mail::assertSent(AccountDeletionScheduledMail::class, fn (AccountDeletionScheduledMail $mail): bool => $mail->hasTo($this->user->email));
    expect(Activity::where('event', 'deletion-requested')->exists())->toBeTrue();
});

test('the grace period follows the config', function () {
    config(['wxnotify.account_deletion_days' => 3]);

    app(RequestAccountDeletion::class)($this->user);

    expect($this->user->refresh()->deletionDate()->isSameDay(now()->addDays(3)))->toBeTrue();
});

test('staff and owners cannot request deletion', function (string $flag) {
    $this->user->forceFill([$flag => true])->save();

    expect($this->user->canRequestDeletion())->toBeFalse()
        ->and(fn () => app(RequestAccountDeletion::class)($this->user))->toThrow(DomainException::class);

    expect($this->user->refresh()->isPendingDeletion())->toBeFalse();
})->with(['is_staff', 'is_owner']);

test('cancelling clears the request', function () {
    app(RequestAccountDeletion::class)($this->user);

    app(CancelAccountDeletion::class)($this->user->refresh());

    expect($this->user->refresh()->isPendingDeletion())->toBeFalse();
});

test('the profile page asks for the account email before scheduling deletion', function () {
    livewire(EditProfile::class)
        ->callAction(TestAction::make('requestAccountDeletion')->schemaComponent('delete_account', schema: 'form'), ['email' => 'wrong@example.com'])
        ->assertHasActionErrors(['email']);

    expect($this->user->refresh()->isPendingDeletion())->toBeFalse();

    livewire(EditProfile::class)
        ->callAction(TestAction::make('requestAccountDeletion')->schemaComponent('delete_account', schema: 'form'), ['email' => $this->user->email])
        ->assertHasNoActionErrors();

    expect($this->user->refresh()->isPendingDeletion())->toBeTrue();
});

test('the profile page offers cancelling while deletion is pending', function () {
    app(RequestAccountDeletion::class)($this->user);
    $this->actingAs($this->user->refresh());

    livewire(EditProfile::class)
        ->callAction(TestAction::make('cancelAccountDeletion')->schemaComponent('delete_account', schema: 'form'));

    expect($this->user->refresh()->isPendingDeletion())->toBeFalse();
});

test('a banner on the dashboard shows while deletion is pending', function () {
    $this->get('/dashboard')->assertSuccessful()->assertDontSee('scheduled for deletion');

    app(RequestAccountDeletion::class)($this->user);
    $this->actingAs($this->user->refresh());

    $this->get('/dashboard')->assertSuccessful()->assertSee('scheduled for deletion');
});

test('alerts are refused for an account pending deletion and work again once it is cancelled', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->for($this->user)->create();

    app(RequestAccountDeletion::class)($this->user);

    $result = app(ChannelDispatcher::class)->send($channel->refresh(), 'Body', 'Title');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChannelDispatcher::PENDING_DELETION_REFUSAL);

    app(CancelAccountDeletion::class)($this->user->refresh());

    expect(app(ChannelDispatcher::class)->send($channel->refresh(), 'Body', 'Title')->error)
        ->not->toBe(ChannelDispatcher::PENDING_DELETION_REFUSAL);
});

test('the app cannot sign in to an account pending deletion', function () {
    app(RequestAccountDeletion::class)($this->user);
    $this->actingAs($this->user->refresh());

    $query = [
        'challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 43), true)), '+/', '-_'), '='),
        'state' => str_repeat('s', 32),
        'device_name' => 'iPhone 16',
        'redirect' => 'wxnotify://auth/callback',
    ];

    $this->get('/app-auth/authorize?'.http_build_query($query))->assertForbidden();
    $this->post('/app-auth/authorize', [...$query, 'decision' => 'allow'])->assertForbidden();
});

test('the purge deletes accounts whose window has passed, with what does not cascade', function () {
    $gone = User::factory()->create(['deletion_requested_at' => now()->subDays(8)]);
    $gone->createToken('iPhone', ['app']);
    UserSession::factory()->for($gone)->create();
    $location = Location::factory()->for($gone)->create();
    $channel = NotificationChannel::factory()->for($gone)->create();
    activity()->causedBy($gone)->event('revoked')->withProperties(['device' => 'My private phone'])->log('Revoked a signed-in app device');

    $this->artisan('accounts:purge-deleted')->assertSuccessful();

    expect(User::find($gone->id))->toBeNull()
        ->and(Location::find($location->id))->toBeNull()
        ->and(NotificationChannel::find($channel->id))->toBeNull()
        ->and(UserSession::where('user_id', $gone->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $gone->id)->exists())->toBeFalse()
        ->and(Activity::where('causer_id', $gone->id)->exists())->toBeFalse();

    $entry = Activity::where('event', 'account-deleted')->sole();

    expect($entry->properties->get('user_id'))->toBe($gone->id)
        ->and(json_encode($entry->toArray()))->not->toContain($gone->email)->not->toContain('My private phone');
});

test('the purge leaves alone accounts still inside the window, not requested, and staff promoted since', function () {
    $waiting = User::factory()->create(['deletion_requested_at' => now()->subDays(6)]);
    $normal = User::factory()->create();
    $promoted = User::factory()->create(['deletion_requested_at' => now()->subDays(8)]);
    $promoted->forceFill(['is_staff' => true])->save();

    $this->artisan('accounts:purge-deleted')->assertSuccessful();

    expect(User::find($waiting->id))->not->toBeNull()
        ->and(User::find($normal->id))->not->toBeNull()
        ->and(User::find($promoted->id))->not->toBeNull();
});

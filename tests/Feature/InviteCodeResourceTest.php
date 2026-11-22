<?php

use App\Enums\InviteCodeStatus;
use App\Filament\Admin\Resources\InviteCodes\Pages\ListInviteCodes;
use App\Models\InviteCode;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the table lists codes with a status badge', function () {
    $active = InviteCode::factory()->create();
    $revoked = InviteCode::factory()->revoked()->create();

    livewire(ListInviteCodes::class)
        ->assertCanSeeTableRecords([$active, $revoked])
        ->assertTableColumnStateSet('status', InviteCodeStatus::Active->label(), $active)
        ->assertTableColumnStateSet('status', InviteCodeStatus::Revoked->label(), $revoked);
});

test('the status filter returns only matching rows', function () {
    $active = InviteCode::factory()->create();
    $expired = InviteCode::factory()->expired()->create();
    $usedUp = InviteCode::factory()->usedUp()->create();

    livewire(ListInviteCodes::class)
        ->filterTable('status', InviteCodeStatus::Expired->value)
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$active, $usedUp]);
});

test('generate invites creates several codes and hides the email field', function () {
    livewire(ListInviteCodes::class)
        ->mountAction('generateInvites')
        ->fillForm(['count' => 3, 'note' => 'Beta'])
        ->assertSchemaComponentHidden('email')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(InviteCode::count())->toBe(3)
        ->and(InviteCode::where('created_by', $this->staff->id)->where('note', 'Beta')->count())->toBe(3);
});

test('generate invites with a count of one shows the email field and locks the code', function () {
    livewire(ListInviteCodes::class)
        ->callAction('generateInvites', ['count' => 1, 'email' => 'Jo@Example.com']);

    expect(InviteCode::sole()->email)->toBe('jo@example.com');
});

test('a new shared code with max uses creates one row', function () {
    livewire(ListInviteCodes::class)
        ->callAction('newSharedCode', ['code' => 'friends-2027', 'max_uses' => 5]);

    $code = InviteCode::sole();

    expect($code->code)->toBe('FRIENDS2027')
        ->and($code->max_uses)->toBe(5);
});

test('a new shared code that clashes shows a form error', function () {
    InviteCode::factory()->create(['code' => 'FRIENDS2027']);

    livewire(ListInviteCodes::class)
        ->callAction('newSharedCode', ['code' => 'friends 2027', 'max_uses' => 5])
        ->assertHasFormErrors(['code']);

    expect(InviteCode::count())->toBe(1);
});

test('the copy invite link action exists and builds the register link', function () {
    $code = InviteCode::factory()->create(['code' => 'ABCDEFGH']);

    livewire(ListInviteCodes::class)
        ->assertActionExists(TestAction::make('copyLink')->table($code));

    expect($code->inviteUrl())->toContain('register?code=ABCD-EFGH');
});

test('revoke and restore toggle revoked_at', function () {
    $code = InviteCode::factory()->create();

    livewire(ListInviteCodes::class)
        ->callAction(TestAction::make('revoke')->table($code));

    expect($code->fresh()->revoked_at)->not->toBeNull();

    livewire(ListInviteCodes::class)
        ->callAction(TestAction::make('restore')->table($code));

    expect($code->fresh()->revoked_at)->toBeNull();
});

test('delete is hidden for a used code and works for an unused one', function () {
    $used = InviteCode::factory()->usedUp()->create();
    $unused = InviteCode::factory()->create();

    livewire(ListInviteCodes::class)
        ->assertActionHidden(TestAction::make('delete')->table($used))
        ->callAction(TestAction::make('delete')->table($unused));

    expect(InviteCode::find($unused->id))->toBeNull()
        ->and(InviteCode::find($used->id))->not->toBeNull();
});

test('a non-staff user cannot reach the page', function () {
    $this->actingAs(User::factory()->create(['is_staff' => false]));

    $this->get('/admin/invite-codes')->assertForbidden();
});

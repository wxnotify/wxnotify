<?php

use App\Enums\InviteCodeStatus;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('normalises a code to upper case without spaces or dashes', function () {
    expect(InviteCode::normalise(' ab-cd 12 '))->toBe('ABCD12')
        ->and(InviteCode::normalise(null))->toBe('');
});

it('generates eight characters without look-alike characters', function () {
    foreach (range(1, 200) as $ignored) {
        $code = InviteCode::generateCode();

        expect($code)->toHaveLength(8)
            ->and($code)->toMatch('/^[A-Z2-9]{8}$/')
            ->and($code)->not->toMatch('/[0O1IL]/');
    }
});

it('shows an eight character code in two groups and a custom code as it is', function () {
    expect(InviteCode::factory()->make(['code' => 'ABCDEFGH'])->displayCode())->toBe('ABCD-EFGH')
        ->and(InviteCode::factory()->make(['code' => 'FRIENDS2026'])->displayCode())->toBe('FRIENDS2026');
});

it('reports the status of each factory state', function () {
    $this->freezeTime();

    expect(InviteCode::factory()->create()->status())->toBe(InviteCodeStatus::Active)
        ->and(InviteCode::factory()->shared(5)->create()->status())->toBe(InviteCodeStatus::Active)
        ->and(InviteCode::factory()->forEmail('Guest@Example.com')->create()->email)->toBe('guest@example.com')
        ->and(InviteCode::factory()->usedUp()->create()->status())->toBe(InviteCodeStatus::UsedUp)
        ->and(InviteCode::factory()->expired()->create()->status())->toBe(InviteCodeStatus::Expired)
        ->and(InviteCode::factory()->revoked()->create()->status())->toBe(InviteCodeStatus::Revoked)
        ->and(InviteCode::factory()->create(['expires_at' => now()])->status())->toBe(InviteCodeStatus::Expired)
        ->and(InviteCode::factory()->create(['expires_at' => now()->addSecond()])->status())->toBe(InviteCodeStatus::Active)
        ->and(InviteCode::factory()->create(['max_uses' => null, 'used_count' => 99])->status())->toBe(InviteCodeStatus::Active);
});

it('treats a revoked code as revoked before anything else', function () {
    $code = InviteCode::factory()->revoked()->expired()->create(['max_uses' => 1, 'used_count' => 1]);

    expect($code->status())->toBe(InviteCodeStatus::Revoked);
});

it('treats an expired code as expired before used up', function () {
    $code = InviteCode::factory()->expired()->create(['max_uses' => 1, 'used_count' => 1]);

    expect($code->status())->toBe(InviteCodeStatus::Expired);
});

it('scopes match status exactly', function () {
    $this->freezeTime();

    $active = InviteCode::factory()->create();
    $usedUp = InviteCode::factory()->usedUp()->create();
    $expired = InviteCode::factory()->expired()->create();
    $revoked = InviteCode::factory()->revoked()->create();
    $revokedAndExpired = InviteCode::factory()->revoked()->expired()->create();
    $expiredAndUsedUp = InviteCode::factory()->expired()->usedUp()->create();

    expect(InviteCode::active()->pluck('id')->all())->toBe([$active->id])
        ->and(InviteCode::usedUp()->pluck('id')->all())->toBe([$usedUp->id])
        ->and(InviteCode::expired()->pluck('id')->sort()->values()->all())->toBe([$expired->id, $expiredAndUsedUp->id])
        ->and(InviteCode::revoked()->pluck('id')->sort()->values()->all())->toBe([$revoked->id, $revokedAndExpired->id]);
});

it('keeps codes unique at the database', function () {
    InviteCode::factory()->create(['code' => 'SAMECODE']);

    InviteCode::factory()->create(['code' => 'SAMECODE']);
})->throws(UniqueConstraintViolationException::class);

it('keeps the code when its creator is deleted', function () {
    $creator = User::factory()->create();
    $code = InviteCode::factory()->create(['created_by' => $creator->id]);

    expect($code->creator->is($creator))->toBeTrue();

    $creator->delete();

    expect($code->fresh()->created_by)->toBeNull();
});

it('keeps its users when a code is deleted', function () {
    $code = InviteCode::factory()->create();
    $user = User::factory()->create(['invite_code_id' => $code->id]);

    expect($code->users->modelKeys())->toBe([$user->id])
        ->and($user->inviteCode->is($code))->toBeTrue();

    $code->delete();

    expect($user->fresh()->invite_code_id)->toBeNull();
});

<?php

use App\Actions\Account\CreateInviteCodes;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('it creates the requested number of single-use codes with note, expiry and creator', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $expiresAt = now()->addWeek();

    $codes = app(CreateInviteCodes::class)(3, 'Beta group', $expiresAt, null, $staff);

    expect($codes)->toHaveCount(3)
        ->and(InviteCode::count())->toBe(3);

    $codes->each(function (InviteCode $code) use ($staff, $expiresAt) {
        expect($code->note)->toBe('Beta group')
            ->and($code->max_uses)->toBe(1)
            ->and($code->used_count)->toBe(0)
            ->and($code->created_by)->toBe($staff->id)
            ->and($code->expires_at->equalTo($expiresAt->copy()->startOfSecond()))->toBeTrue()
            ->and(strlen($code->code))->toBe(InviteCode::GENERATED_LENGTH);
    });
});

test('the email is stored trimmed and lower-case', function () {
    $codes = app(CreateInviteCodes::class)(1, null, null, '  Jane@Example.COM ', null);

    expect($codes->first()->email)->toBe('jane@example.com');
});

test('a custom code is stored normalised', function () {
    $codes = app(CreateInviteCodes::class)(1, null, null, null, null, 'spring-2027');

    expect($codes->first()->code)->toBe('SPRING2027');
});

test('a custom code that normalises to an existing one is refused', function () {
    InviteCode::factory()->create(['code' => 'SPRING2027']);

    expect(fn () => app(CreateInviteCodes::class)(1, null, null, null, null, 'spring 2027'))
        ->toThrow(ValidationException::class);

    expect(InviteCode::count())->toBe(1);
});

test('a null max uses makes an unlimited code', function () {
    $codes = app(CreateInviteCodes::class)(1, null, null, null, null, 'OPENDOOR', null);

    expect($codes->first()->max_uses)->toBeNull();
});

test('a custom code shorter than four characters after normalising is refused', function () {
    expect(fn () => app(CreateInviteCodes::class)(1, null, null, null, null, 'a-b!'))
        ->toThrow(ValidationException::class);
});

test('a custom code is only valid for a count of one', function () {
    expect(fn () => app(CreateInviteCodes::class)(2, null, null, null, null, 'TWOCODES'))
        ->toThrow(ValidationException::class);
});

test('a count above fifty is refused', function () {
    expect(fn () => app(CreateInviteCodes::class)(51, null, null, null, null))
        ->toThrow(ValidationException::class);

    expect(InviteCode::count())->toBe(0);
});

<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * User deliberately excludes is_staff/is_owner/tier_override/
     * tier_override_reason/email_verified_at from its #[Fillable] list --
     * the same mass-assignment guard EditUser::handleRecordUpdate() already
     * bypasses via forceFill() for the one legitimate staff-only edit path.
     * Creation needs the identical bypass, via Eloquent's forceCreate().
     */
    protected function handleRecordCreation(array $data): User
    {
        return User::forceCreate($data);
    }

    /**
     * `password_setup` is a UI-only radio (not a model attribute) deciding
     * whether the subscriber sets their own password via email or staff
     * sets it directly; strip it before mass assignment. Either way the
     * account is treated as staff-vouched: no separate email-verification
     * step blocks the subscriber's first login.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // password_setup is dehydrated(false) (it's a UI-only choice, not a
        // model attribute), so it never reaches $data -- read it from the
        // form's own raw state instead, same as afterCreate() below does.
        $sendResetLink = ($this->data['password_setup'] ?? 'send_reset_link') === 'send_reset_link';

        if ($sendResetLink) {
            // Nothing an admin (or anyone reading this app's source) ever
            // knows: a real password never has to pass through staff hands.
            $data['password'] = Hash::make(Str::random(40));
        }

        $data['email_verified_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        if (($this->data['password_setup'] ?? 'send_reset_link') !== 'send_reset_link') {
            return;
        }

        /** @var User $user */
        $user = $this->record;

        // getResetPasswordUrl()/getAuthPasswordBroker() both read the
        // CURRENT panel -- this page runs inside /admin, but the link must
        // send a non-staff subscriber to /dashboard's reset-password page,
        // not an admin-only route they can't reach.
        $dashboardPanel = Filament::getPanel('dashboard');
        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel($dashboardPanel);

        try {
            /** @var PasswordBroker $broker */
            $broker = Password::broker(Filament::getAuthPasswordBroker());

            $broker->sendResetLink(
                ['email' => $user->email],
                function (CanResetPassword $user, string $token): void {
                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);

                    $user->notify($notification);
                },
            );
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

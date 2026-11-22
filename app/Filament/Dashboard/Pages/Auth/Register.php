<?php

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\Account\SignUpGate;
use App\Actions\Account\SignUpRefused;
use App\Enums\SignUpPolicy;
use App\Enums\SignUpRefusal;
use Closure;
use Coderflex\FilamentTurnstile\Forms\Components\Turnstile;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class Register extends BaseRegister
{
    #[Unexposed]
    public function mount(): void
    {
        $gate = app(SignUpGate::class);

        if ($gate->policy() === SignUpPolicy::Closed) {
            Notification::make()
                ->title(SignUpRefusal::Closed->message())
                ->danger()
                ->persistent()
                ->send();

            $this->redirect(Filament::getLoginUrl());

            return;
        }

        parent::mount();

        $code = request()->query('code');

        if ($gate->policy() !== SignUpPolicy::InviteOnly || ! is_string($code) || $code === '') {
            return;
        }

        $this->form->fill(['invite_code' => $code]);

        if ($gate->check($code, null)->allowed) {
            $gate->rememberCode($code);
        }
    }

    /**
     * The closure only creates the user: Filament fires Registered, sends the verification email and
     * signs the user in after this returns, outside the gate's transaction.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $gate = app(SignUpGate::class);

        try {
            $user = $gate->admit(
                $this->data['invite_code'] ?? null,
                $data['email'],
                fn (): Model => parent::handleRegistration($data),
            );
        } catch (SignUpRefused $refused) {
            throw ValidationException::withMessages([
                match ($refused->refusal) {
                    SignUpRefusal::Closed => 'data.email',
                    SignUpRefusal::InvalidCode, SignUpRefusal::EmailMismatch => 'data.invite_code',
                } => $refused->getMessage(),
            ]);
        }

        $gate->forgetCode();

        return $user;
    }

    protected function inviteCodeFormComponent(): TextInput
    {
        $gate = app(SignUpGate::class);

        return TextInput::make('invite_code')
            ->label('Invite code')
            ->dehydrated(false)
            ->visible(fn (): bool => $gate->policy() === SignUpPolicy::InviteOnly)
            ->required(fn (): bool => $gate->policy() === SignUpPolicy::InviteOnly)
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($gate, $get): void {
                $decision = $gate->check(is_string($value) ? $value : null, $get('email'));

                if (! $decision->allowed && $decision->refusal !== SignUpRefusal::Closed) {
                    $fail($decision->message());
                }
            });
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->inviteCodeFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent()
                    ->rule(Password::min(8)->letters()->mixedCase()->numbers()->symbols())
                    ->extraInputAttributes(['data-password-input' => true]),
                View::make('filament.dashboard.password-requirements'),
                $this->getPasswordConfirmationFormComponent(),
                ...$this->captchaComponents(),
            ]);
    }

    /**
     * The sign-up captcha. A self-hosted install (WXNOTIFY_MODE=selfhost) that has no Cloudflare Turnstile
     * keys signs people up without one. The skip needs BOTH keys blank: with either key set the check
     * applies, so a half-configured install fails loudly. The hosted cloud service never skips it, so a
     * missing key there is an error, not silently disabled bot protection.
     *
     * @return array<int, Turnstile>
     */
    private function captchaComponents(): array
    {
        $keysBlank = blank(config('turnstile.turnstile_site_key')) && blank(config('turnstile.turnstile_secret_key'));

        return config('wxnotify.mode') === 'selfhost' && $keysBlank ? [] : [Turnstile::make('turnstile_token')];
    }
}

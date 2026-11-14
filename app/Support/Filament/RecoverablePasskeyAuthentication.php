<?php

namespace App\Support\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Happenv\FilamentPasskeys\Actions\SetUpPasskeyAuthenticationAction;
use Happenv\FilamentPasskeys\PasskeyAuthentication;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Livewire\Component;
use SensitiveParameter;

/**
 * Passkeys as a second factor, plus the same recovery codes Filament's app authentication uses. Both
 * factors share one set of codes (the users.app_authentication_recovery_codes column), so a subscriber
 * who loses their phone or their passkey device can still get in, whichever factor they set up.
 *
 * The codes are made the first time a passkey is added and can be remade with the account password.
 * Entering one in the passkey challenge uses it up. The passkey ceremony submits the challenge form
 * itself, so the "Confirm sign in" button stays visible (see config/filament-passkeys.php) for the
 * recovery-code path.
 */
class RecoverablePasskeyAuthentication extends PasskeyAuthentication
{
    /**
     * @return array<int, mixed>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        [$credential] = parent::getChallengeFormComponents($user);

        return [
            $credential
                ->required(fn (Get $get): bool => ! $get('useRecoveryCode') || blank($get('recoveryCode')))
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => ! $get('useRecoveryCode'))),
            TextInput::make('recoveryCode')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.label'))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.validation_attribute'))
                ->password()
                ->revealable(Filament::arePasswordsRevealable())
                ->autocomplete('one-time-code')
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                    if (blank($value)) {
                        return;
                    }

                    if (is_string($value) && $this->hasRecoveryCodes($user) && AppAuthentication::make()->verifyRecoveryCode($value, $user)) {
                        return;
                    }

                    $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                })
                ->visible(fn (Get $get): bool => (bool) $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getActions(): array
    {
        $user = Filament::auth()->user();

        return [
            SetUpPasskeyAuthenticationAction::make($this)
                ->after(function (Component $livewire): void {
                    $user = Filament::auth()->user();

                    // New codes only when this passkey is the account's first second factor. A
                    // subscriber who already has app codes keeps them, and a stale set left behind
                    // after every factor was turned off is replaced rather than silently reused.
                    if ($user->passkeys()->count() > 1 || AppAuthentication::make()->isEnabled($user)) {
                        return;
                    }

                    $livewire->mountAction('showPasskeyRecoveryCodes', arguments: ['recoveryCodes' => $this->makeRecoveryCodes($user)]);
                })
                ->registerModalActions([$this->showRecoveryCodesAction()]),
            $this->regenerateRecoveryCodesAction()
                ->visible(fn (): bool => $this->isEnabled($user)),
            parent::getActions()[1]
                ->after(function (): void {
                    $user = Filament::auth()->user();

                    if (! AppAuthentication::make()->isEnabled($user)) {
                        AppAuthentication::make()->saveRecoveryCodes($user, null);
                    }
                }),
        ];
    }

    public function hasRecoveryCodes(?Authenticatable $user): bool
    {
        return filled($user?->getAppAuthenticationRecoveryCodes());
    }

    /**
     * Make a fresh set, store it hashed and return the plain codes (shown once).
     *
     * @return array<int, string>
     */
    public function makeRecoveryCodes(Authenticatable $user): array
    {
        $appAuthentication = AppAuthentication::make();
        $codes = $appAuthentication->generateRecoveryCodes();

        $appAuthentication->saveRecoveryCodes($user, $codes);

        return $codes;
    }

    private function regenerateRecoveryCodesAction(): Action
    {
        return Action::make('regeneratePasskeyRecoveryCodes')
            ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.label'))
            ->color('gray')
            ->icon(Heroicon::ArrowPath)
            ->link()
            ->modalWidth(Width::Large)
            ->modalHeading(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.heading'))
            ->modalDescription(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.description'))
            ->schema([
                TextInput::make('password')
                    ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.password.label'))
                    ->validationAttribute(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.password.validation_attribute'))
                    ->currentPassword(guard: Filament::getAuthGuard())
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->required()
                    ->dehydrated(false),
            ])
            ->modalSubmitAction(fn (Action $action) => $action
                ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.actions.submit.label'))
                ->color('danger'))
            ->action(function (Component $livewire): void {
                $livewire->mountAction('showPasskeyRecoveryCodes', arguments: [
                    'recoveryCodes' => $this->makeRecoveryCodes(Filament::auth()->user()),
                ]);

                Notification::make()
                    ->title(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.notifications.regenerated.title'))
                    ->success()
                    ->send();
            })
            ->registerModalActions([$this->showRecoveryCodesAction()])
            ->rateLimit(5);
    }

    private function showRecoveryCodesAction(): Action
    {
        return Action::make('showPasskeyRecoveryCodes')
            ->modalHeading(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.heading'))
            ->modalDescription(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.description'))
            ->schema(fn (array $arguments): array => [
                Group::make([
                    UnorderedList::make(fn (): array => array_map(
                        fn (string $recoveryCode): Text => Text::make($recoveryCode)
                            ->fontFamily(FontFamily::Mono)
                            ->size('xs')
                            ->color('neutral'),
                        $arguments['recoveryCodes'],
                    ))
                        ->size('xs'),
                    Text::make(fn (): Htmlable => new HtmlString(
                        __('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.0').
                        ' '.
                        Action::make('copy')
                            ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.copy.label'))
                            ->link()
                            ->alpineClickHandler('
                                window.navigator.clipboard.writeText('.Js::from(implode(PHP_EOL, $arguments['recoveryCodes'])).')
                                $tooltip('.Js::from(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.messages.copied')).', {
                                    theme: $store.theme,
                                })
                            ')
                            ->toHtml().
                        ' '.
                        __('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.1').
                        ' '.
                        Action::make('download')
                            ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.download.label'))
                            ->link()
                            ->url('data:application/octet-stream,'.urlencode(implode(PHP_EOL, $arguments['recoveryCodes'])))
                            ->extraAttributes(['download' => 'recovery-codes.txt'])
                            ->toHtml().
                        ' '.
                        __('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.2')
                    )),
                ])
                    ->dense(),
            ])
            ->modalWidth(Width::Large)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalCloseButton(false)
            ->modalSubmitAction(fn (Action $action) => $action
                ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.show_new_recovery_codes.modal.actions.submit.label'))
                ->color('danger'))
            ->modalCancelAction(false)
            ->cancelParentActions();
    }
}

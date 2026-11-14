<?php

namespace App\Filament\Dashboard\Pages\Auth;

use App\Actions\Account\CancelAccountDeletion;
use App\Actions\Account\RequestAccountDeletion;
use App\Enums\UnitSystem;
use App\Support\Timezones;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use STS\FilamentImpersonate\ImpersonateManager;
use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                ToggleButtons::make('unit_system')
                    ->label('Units')
                    ->options(collect(UnitSystem::cases())->mapWithKeys(fn (UnitSystem $system): array => [$system->value => $system->label()])->all())
                    ->inline()
                    ->required()
                    ->helperText('Temperatures, wind, distances and pressure in your emails, alerts and alert forms.'),
                TimezoneSelect::make('timezone')
                    ->label('Timezone')
                    ->searchable()
                    ->getTimezoneFromBrowser()
                    ->hint(fn (Get $get): ?string => blank($this->getUser()->timezone) && filled($get('timezone'))
                        ? 'Not saved yet. Click Save changes to use this timezone for your alerts.'
                        : null)
                    ->hintColor('warning')
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (filled($state) && ! Timezones::isValid($state)) {
                            $set('timezone', Timezones::normalise($state));
                        }
                    })
                    ->helperText('Used when a location has no timezone of its own (such as a mobile location), for the day boundaries on your dashboard charts, and as the starting value for new locations. A location\'s own timezone always takes priority. Clear it to stop using one.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->deleteAccountSection(),
            ]);
    }

    /**
     * Deleting is requested here and happens after wxnotify.account_deletion_days (see RequestAccountDeletion). The
     * section's id is the anchor the mobile app and the emails link to.
     */
    private function deleteAccountSection(): ?Section
    {
        $user = $this->getUser();

        if (! $user->isPendingDeletion() && ! $user->canRequestDeletion()) {
            return null;
        }

        $isImpersonating = fn (): bool => app(ImpersonateManager::class)->isImpersonating();

        return Section::make('Delete account')
            ->id('delete-account')
            ->description($user->isPendingDeletion()
                ? 'Your account is scheduled to be deleted on '.$user->deletionDate()->format('F j, Y').'. Your alerts are paused. Cancel to keep it.'
                : 'Permanently delete your account and everything in it. You have '.config('wxnotify.account_deletion_days').' days to change your mind: your alerts pause and you are signed out everywhere now, and signing back in lets you cancel.')
            ->schema([
                Actions::make([
                    Action::make('requestAccountDeletion')
                        ->label('Delete my account')
                        ->color('danger')
                        ->visible(! $user->isPendingDeletion())
                        ->disabled($isImpersonating)
                        ->requiresConfirmation()
                        ->modalHeading('Delete your account?')
                        ->modalDescription('Your alerts pause and every browser and phone is signed out now. Your account and all its data are permanently deleted in '.config('wxnotify.account_deletion_days').' days unless you sign in and cancel.')
                        ->schema([
                            TextInput::make('email')
                                ->label('Type your email address to confirm')
                                ->required()
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! is_string($value) || ! hash_equals(mb_strtolower($this->getUser()->email), mb_strtolower(trim($value)))) {
                                        $fail('That is not the email address on this account.');
                                    }
                                }),
                        ])
                        ->action(function (): mixed {
                            app(RequestAccountDeletion::class)($this->getUser());

                            Filament::auth()->logout();
                            session()->invalidate();
                            session()->regenerateToken();

                            return redirect(Filament::getLoginUrl());
                        }),
                    Action::make('cancelAccountDeletion')
                        ->label('Cancel deletion')
                        ->visible($user->isPendingDeletion())
                        ->action(function (): void {
                            app(CancelAccountDeletion::class)($this->getUser());

                            Notification::make()->title('Deletion cancelled')->success()->send();
                        }),
                ])->key('delete_account'),
            ]);
    }
}

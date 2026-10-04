<?php

namespace App\Filament\Dashboard\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Laravel\Sanctum\PersonalAccessToken;
use STS\FilamentImpersonate\ImpersonateManager;
use UnitEnum;

/**
 * The phones signed in to the WxNotify app, with a way to sign one out remotely (a lost
 * phone). The query is scoped to the signed-in subscriber itself: this panel's access gate
 * allows everyone, so scoping is the protection. App tokens are exactly the ones whose
 * abilities are ["app"]; Public API keys never appear here.
 */
class SignedInDevices extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.dashboard.pages.signed-in-devices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Signed-in devices';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => auth()->user()->tokens()->getQuery()->where('abilities', json_encode(['app'])))
            ->defaultSort('last_used_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Device'),
                TextColumn::make('created_at')->label('Signed in')->since(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('expires_at')->label('Expires')->date(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Sign this device out?')
                    ->modalDescription('The app on this device is signed out the next time it contacts WxNotify.')
                    ->disabled(fn (): bool => app(ImpersonateManager::class)->isImpersonating())
                    ->tooltip(fn (): ?string => app(ImpersonateManager::class)->isImpersonating()
                        ? 'Not available while impersonating a subscriber'
                        : null)
                    ->action(function (PersonalAccessToken $record): void {
                        abort_unless(
                            $record->tokenable_type === User::class && (int) $record->tokenable_id === auth()->id(),
                            403,
                        );

                        activity()
                            ->performedOn(auth()->user())
                            ->causedBy(auth()->user())
                            ->event('revoked')
                            ->withProperties(['device' => $record->name])
                            ->log('Revoked a signed-in app device');

                        $record->delete();

                        Notification::make()->title('Device signed out')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No signed-in devices')
            ->emptyStateDescription('Sign in to the WxNotify app and the phone shows up here.');
    }
}

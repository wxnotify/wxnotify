<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\User;
use App\Models\UserSession;
use App\Support\UserAgentLabel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * The web browsers signed in to this subscriber's account, with a way to sign one (or every other one) out.
 * Shown only on the "Where you're signed in" page, never on the dashboard. The query is scoped to the
 * signed-in subscriber and the current browser can never be signed out from here. Social-login accounts
 * have a random password they do not know, so these actions ask for a plain confirmation, not a password.
 */
class BrowserSessionsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Web browsers';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => UserSession::query()->ownedBy(auth()->user()))
            ->defaultSort('last_activity', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('user_agent')
                    ->label('Browser')
                    ->formatStateUsing(fn (?string $state): string => UserAgentLabel::for($state)),
                TextColumn::make('ip_address')->label('IP address'),
                TextColumn::make('last_activity')
                    ->label('Last active')
                    ->state(fn (UserSession $record): string => $record->isCurrent() ? 'This browser' : $record->lastActiveAt()->diffForHumans())
                    ->badge(fn (UserSession $record): bool => $record->isCurrent())
                    ->color('success'),
            ])
            ->headerActions([
                $this->guarded(Action::make('signOutOthers'))
                    ->label('Sign out all other browsers')
                    ->icon(Heroicon::ArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Sign out all other browsers?')
                    ->modalDescription('Every web browser except this one is signed out the next time it contacts WxNotify. The WxNotify app on your phone stays signed in.')
                    ->action(function (): void {
                        $count = UserSession::query()
                            ->ownedBy(auth()->user())
                            ->where('id', '!=', session()->getId())
                            ->delete();

                        $this->logSignOut(['count' => $count]);

                        Notification::make()->title('Other browsers signed out')->success()->send();
                    }),
            ])
            ->recordActions([
                $this->guarded(Action::make('signOut'))
                    ->label('Sign out')
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->hidden(fn (UserSession $record): bool => $record->isCurrent())
                    ->requiresConfirmation()
                    ->modalHeading('Sign this browser out?')
                    ->action(function (UserSession $record): void {
                        abort_unless((int) $record->user_id === auth()->id() && ! $record->isCurrent(), 403);

                        $this->logSignOut(['browser' => UserAgentLabel::for($record->user_agent)]);

                        $record->delete();

                        Notification::make()->title('Browser signed out')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No other browsers')
            ->emptyStateDescription('This is the only web browser signed in to your account.');
    }

    private function guarded(Action $action): Action
    {
        return $action
            ->disabled(fn (): bool => app(ImpersonateManager::class)->isImpersonating())
            ->tooltip(fn (): ?string => app(ImpersonateManager::class)->isImpersonating()
                ? 'Not available while impersonating a subscriber'
                : null);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logSignOut(array $properties): void
    {
        /** @var User $user */
        $user = auth()->user();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('revoked')
            ->withProperties($properties)
            ->log('Signed out a web browser');
    }
}

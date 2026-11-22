<?php

namespace App\Filament\Admin\Resources\InviteCodes\Tables;

use App\Enums\InviteCodeStatus;
use App\Models\InviteCode;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;

class InviteCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('creator'))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->state(fn (InviteCode $record): string => $record->displayCode())
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query->where('code', 'like', '%'.InviteCode::normalise($search).'%')
                            ->orWhere('note', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                    )),
                TextColumn::make('status')
                    ->state(fn (InviteCode $record): string => $record->status()->label())
                    ->badge()
                    ->color(fn (InviteCode $record): string => $record->status()->color()),
                TextColumn::make('uses')
                    ->label('Uses')
                    ->state(fn (InviteCode $record): string => $record->used_count.' / '.($record->max_uses ?? 'unlimited')),
                TextColumn::make('email')
                    ->label('Locked to')
                    ->placeholder('Anyone')
                    ->toggleable(),
                TextColumn::make('note')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never')
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InviteCodeStatus::cases())->mapWithKeys(fn (InviteCodeStatus $status): array => [$status->value => $status->label()]))
                    ->query(function (Builder $query, array $data): Builder {
                        return match (InviteCodeStatus::tryFrom((string) ($data['value'] ?? ''))) {
                            InviteCodeStatus::Active => $query->active(),
                            InviteCodeStatus::UsedUp => $query->usedUp(),
                            InviteCodeStatus::Expired => $query->expired(),
                            InviteCodeStatus::Revoked => $query->revoked(),
                            null => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('copyLink')
                    ->label('Copy invite link')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->modalHeading('Invite link')
                    ->modalDescription('Send this link to the person you are inviting.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->fillForm(fn (InviteCode $record): array => ['url' => $record->inviteUrl()])
                    ->schema([
                        TextInput::make('url')
                            ->label('Invite link')
                            ->readOnly()
                            ->extraInputAttributes(['x-on:focus' => '$el.select()']),
                    ])
                    ->extraModalFooterActions(fn (InviteCode $record): array => [
                        Action::make('copyUrl')
                            ->label('Copy')
                            ->icon(Heroicon::OutlinedClipboard)
                            ->color('gray')
                            ->alpineClickHandler('
                                if (navigator.clipboard?.writeText) {
                                    navigator.clipboard.writeText('.Js::from($record->inviteUrl()).')
                                } else {
                                    const input = $el.closest(\'.fi-modal-window\')?.querySelector(\'input[readonly]\')
                                    input?.select()
                                    document.execCommand(\'copy\')
                                }
                            '),
                    ]),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The code stops working straight away. People who already joined with it keep their accounts.')
                    ->visible(fn (InviteCode $record): bool => $record->revoked_at === null)
                    ->action(fn (InviteCode $record) => $record->forceFill(['revoked_at' => now()])->save()),
                Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (InviteCode $record): bool => $record->revoked_at !== null)
                    ->action(fn (InviteCode $record) => $record->forceFill(['revoked_at' => null])->save()),
                DeleteAction::make()
                    ->visible(fn (InviteCode $record): bool => $record->used_count === 0)
                    ->before(function (InviteCode $record, DeleteAction $action): void {
                        // Re-check on a fresh row: the code may have been spent since the table loaded.
                        if (InviteCode::query()->whereKey($record->getKey())->where('used_count', '>', 0)->exists()) {
                            Notification::make()
                                ->title('This code has been used, so it cannot be deleted')
                                ->body('Revoke it instead.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->emptyStateHeading('No invite codes yet')
            ->emptyStateDescription('Generate invites to let people sign up while registration is invite only.')
            ->defaultSort('created_at', 'desc');
    }
}

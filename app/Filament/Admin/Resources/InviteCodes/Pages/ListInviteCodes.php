<?php

namespace App\Filament\Admin\Resources\InviteCodes\Pages;

use App\Actions\Account\CreateInviteCodes;
use App\Filament\Admin\Resources\InviteCodes\InviteCodeResource;
use App\Models\InviteCode;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ListInviteCodes extends ListRecords
{
    protected static string $resource = InviteCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateInvites')
                ->label('Generate invites')
                ->icon(Heroicon::OutlinedTicket)
                ->modalHeading('Generate invites')
                ->modalDescription('Each code works once. Add an email address to lock a single code to that person.')
                ->schema([
                    TextInput::make('count')
                        ->label('How many codes')
                        ->numeric()
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(CreateInviteCodes::MAX_COUNT)
                        ->required()
                        ->live(),
                    TextInput::make('email')
                        ->label('Lock to email address')
                        ->helperText('Optional. Only this address can use the code.')
                        ->email()
                        ->visible(fn (Get $get): bool => (int) $get('count') === 1),
                    Textarea::make('note')
                        ->label('Note')
                        ->helperText('Only staff see this, for example who the code is for.')
                        ->rows(2)
                        ->maxLength(255),
                    self::expiryField(),
                ])
                ->action(function (array $data): void {
                    $codes = $this->createCodes(
                        count: (int) $data['count'],
                        data: $data,
                        code: null,
                        maxUses: 1,
                    );

                    if ($codes === null) {
                        return;
                    }

                    Notification::make()
                        ->title($codes->count() === 1 ? 'Invite code created' : $codes->count().' invite codes created')
                        ->success()
                        ->send();
                }),
            Action::make('newSharedCode')
                ->label('New shared code')
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->modalHeading('New shared code')
                ->modalDescription('One code that several people can use, for example for a launch post.')
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->helperText('Leave blank to generate one. Letters and digits only; dashes and spaces are ignored.')
                        ->minLength(CreateInviteCodes::MIN_CUSTOM_LENGTH)
                        ->maxLength(32)
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (filled($value) && InviteCode::where('code', InviteCode::normalise($value))->exists()) {
                                $fail('That code is already in use.');
                            }
                        }),
                    TextInput::make('max_uses')
                        ->label('Number of uses')
                        ->helperText('Leave blank for unlimited.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->default(10),
                    Textarea::make('note')
                        ->label('Note')
                        ->rows(2)
                        ->maxLength(255),
                    self::expiryField(),
                ])
                ->action(function (array $data): void {
                    $codes = $this->createCodes(
                        count: 1,
                        data: $data,
                        code: $data['code'] ?? null,
                        maxUses: filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
                    );

                    if ($codes === null) {
                        return;
                    }

                    Notification::make()
                        ->title('Shared code '.$codes->first()->displayCode().' created')
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function expiryField(): DateTimePicker
    {
        return DateTimePicker::make('expires_at')
            ->label('Expires')
            ->helperText('Optional. Leave blank for no expiry.')
            ->minDate(now());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, InviteCode>|null null when the action refused the request
     */
    private function createCodes(int $count, array $data, ?string $code, ?int $maxUses): ?Collection
    {
        /** @var User $staff */
        $staff = auth()->user();

        try {
            return app(CreateInviteCodes::class)(
                count: $count,
                note: $data['note'] ?? null,
                expiresAt: filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
                email: $data['email'] ?? null,
                by: $staff,
                code: $code,
                maxUses: $maxUses,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('The invite codes were not created')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return null;
        }
    }
}

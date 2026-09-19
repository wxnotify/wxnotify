<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\FactoryReset\FactoryResetConfirmation;
use App\Actions\Admin\FactoryReset\PreviewFactoryReset;
use App\Actions\Admin\FactoryReset\VerifyOwnerReauthentication;
use App\Actions\Admin\ResetToFactoryDefaults as RunFactoryReset;
use App\Contracts\PipelineStack;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class ResetToFactoryDefaults extends Page implements HasForms
{
    use InteractsWithForms;

    private const REAUTH_SESSION_KEY = 'factory_reset.reauthenticated_at';

    private const REAUTH_WINDOW_SECONDS = 300;

    protected string $view = 'filament.admin.pages.reset-to-factory-defaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Reset to Factory Defaults';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_owner === true;
    }

    public function mount(): void
    {
        $this->form->fill(['pipeline' => false, 'catalogs' => false, 'subscribers' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('What to reset')
                    ->description('Every group is off by default. Staff and owner accounts and the audit log are never deleted.')
                    ->schema([
                        Toggle::make('pipeline')
                            ->label('Pipeline & dispatch data')
                            ->helperText('Deletes every ingested product (test-generated included), clears active WWA tracking and the delivery log. Archived text already stored in B2/S3 is left in place.')
                            ->live(),
                        Checkbox::make('pipeline_stopped')
                            ->label('I have stopped the pipeline services (Downloader, Alert Engine, GLM, Threshold evaluator, Traccar poller)')
                            ->accepted()
                            ->visible(fn (Get $get): bool => (bool) $get('pipeline') && ! app(PipelineStack::class)->canStop()),
                        Toggle::make('catalogs')
                            ->label('Catalogs')
                            ->helperText('Resets the Product and Notification channel catalogs to their shipped state. Admin-added products and channel types are deleted.')
                            ->live(),
                        Toggle::make('subscribers')
                            ->label('Subscriber data')
                            ->helperText('Permanently deletes every non-staff account with its locations, alert rules and channels. No grace period; unrecoverable.')
                            ->live(),
                    ]),
                Section::make('Confirm')
                    ->visible(fn (): bool => $this->isReauthenticated())
                    ->schema([
                        ViewField::make('preview')
                            ->label('')
                            ->view('filament.admin.pages.factory-reset-preview')
                            ->viewData(fn (): array => ['lines' => $this->previewLines()])
                            ->dehydrated(false),
                        TextInput::make('confirmation')
                            ->label(fn (): string => 'Type '.FactoryResetConfirmation::expectedPhrase($this->toggles()).' to confirm')
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! FactoryResetConfirmation::matches($this->toggles(), (string) $value)) {
                                    $fail('That does not match the confirmation text.');
                                }
                            })
                            ->visible(fn (): bool => $this->isReauthenticated()),
                    ]),
            ]);
    }

    /**
     * @return array{pipeline: bool, catalogs: bool, subscribers: bool}
     */
    protected function toggles(): array
    {
        return [
            'pipeline' => (bool) ($this->data['pipeline'] ?? false),
            'catalogs' => (bool) ($this->data['catalogs'] ?? false),
            'subscribers' => (bool) ($this->data['subscribers'] ?? false),
        ];
    }

    public function isReauthenticated(): bool
    {
        $at = session(self::REAUTH_SESSION_KEY);

        return is_int($at) && (now()->timestamp - $at) <= self::REAUTH_WINDOW_SECONDS;
    }

    /**
     * @return list<string>
     */
    protected function previewLines(): array
    {
        $preview = app(PreviewFactoryReset::class)($this->toggles());
        $lines = [];

        if (isset($preview['pipeline'])) {
            $lines[] = number_format($preview['pipeline']['ingested_products']).' ingested products, '
                .number_format($preview['pipeline']['active_wwas']).' active WWAs, '
                .number_format($preview['pipeline']['delivery_logs']).' delivery log rows';

            if ($preview['real_subscribers_exist']) {
                $lines[] = 'Real subscribers exist: their activity and health widgets will read empty until new data arrives.';
            }
        }

        if (isset($preview['catalogs'])) {
            $lines[] = 'Catalogs restored to shipped state; '.$preview['catalogs']['custom_products'].' custom products removed (affecting '
                .$preview['catalogs']['alert_rules_affected'].' alert rules), '.$preview['catalogs']['custom_channel_types'].' custom channel types removed';
        }

        if (isset($preview['subscribers'])) {
            $lines[] = number_format($preview['subscribers']['users']).' subscribers, '
                .number_format($preview['subscribers']['locations']).' locations, '
                .number_format($preview['subscribers']['alert_rules']).' alert rules, '
                .number_format($preview['subscribers']['channels']).' channels';
        }

        return $lines ?: ['Nothing selected.'];
    }

    public function reauthAction(): Action
    {
        return Action::make('reauth')
            ->label('Continue')
            ->color('danger')
            ->modalHeading('Confirm it is you')
            ->modalDescription('Re-enter your password'.(AppAuthentication::make()->isEnabled(auth()->user()) ? ' and a current authenticator code' : '').' to continue.')
            ->schema([
                TextInput::make('password')->password()->required(),
                TextInput::make('authenticator_code')
                    ->label('Authenticator code')
                    ->visible(fn (): bool => AppAuthentication::make()->isEnabled(auth()->user()))
                    ->required(fn (): bool => AppAuthentication::make()->isEnabled(auth()->user())),
            ])
            ->action(function (array $data): void {
                $ok = app(VerifyOwnerReauthentication::class)(auth()->user(), (string) $data['password'], $data['authenticator_code'] ?? null);

                if (! $ok) {
                    session()->forget(self::REAUTH_SESSION_KEY);
                    Notification::make()->title('Re-authentication failed')->danger()->send();

                    return;
                }

                session([self::REAUTH_SESSION_KEY => now()->timestamp]);
            });
    }

    public function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset')
            ->color('danger')
            ->visible(fn (): bool => $this->isReauthenticated())
            ->action(fn () => $this->runReset());
    }

    public function runReset(): void
    {
        abort_unless(self::canAccess(), 403);

        if (! $this->isReauthenticated()) {
            Notification::make()->title('Re-authenticate first')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        try {
            $result = app(RunFactoryReset::class)($this->toggles(), auth()->user(), (bool) ($data['pipeline_stopped'] ?? false));
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Reset failed')->body($e->getMessage())->danger()->send();

            return;
        }

        session()->forget(self::REAUTH_SESSION_KEY);
        $this->form->fill(['pipeline' => false, 'catalogs' => false, 'subscribers' => false]);

        Notification::make()
            ->title('Reset complete')
            ->body(collect($result)->map(fn (array $counts, string $group): string => $group.': '.json_encode($counts))->implode('; '))
            ->success()
            ->send();
    }
}

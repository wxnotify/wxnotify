<?php

namespace App\Filament\Dashboard\Pages;

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Exceptions\ChannelCodeNotSent;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use BackedEnum;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;
use UnitEnum;

class ConnectChannel extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Alerting';

    protected static ?string $navigationLabel = 'New Channel';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.dashboard.pages.connect-channel';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $registry = app(ChannelTypeRegistry::class);
        $picker = $registry->forPicker();

        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Choose a channel')
                        ->schema([
                            RadioCards::make('channel_type')
                                ->hiddenLabel()
                                ->options($picker->mapWithKeys(fn (array $row) => [$row['type']->slug() => $row['type']->label()]))
                                ->icons($picker->mapWithKeys(fn (array $row) => [$row['type']->slug() => $row['type']->icon()]))
                                ->disableOptionWhen(fn (string $value): bool => ! auth()->user()->canAddChannel($value))
                                // tonegabes/filament-better-options v1.4's Heroicons fallback for the
                                // radio idle indicator is 'heroicon-o-circle', which doesn't exist in
                                // Heroicons (confirmed: no outline or solid "circle" icon ships in the
                                // installed heroicons set) — every unselected card throws
                                // Svg by name "o-circle" from set "heroicons" not found. Hiding the
                                // indicator sidesteps the broken default entirely; the channel icon
                                // itself plus the card's selected-state styling already communicate
                                // selection without it.
                                ->hiddenIndicator()
                                ->columns(3)
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (?string $state, Set $set) => $this->resetSetupStep($state, $set)),
                        ]),
                    Step::make('Set it up')
                        ->schema(fn (Get $get) => $get('channel_type')
                            ? $registry->find($get('channel_type'))?->formSchema() ?? []
                            : [])
                        ->afterValidation(function (Get $get, Set $set) {
                            $type = app(ChannelTypeRegistry::class)->find($get('channel_type'));

                            try {
                                $channel = app(BeginChannelConnection::class)(auth()->user(), $type, $get());
                            } catch (\InvalidArgumentException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                throw new Halt;
                            } catch (ChannelCodeNotSent) {
                                Notification::make()
                                    ->title('Couldn\'t send your confirmation code')
                                    ->body('The notification service is temporarily unavailable. Please try again in a moment.')
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            $set('channel_id', $channel->id);
                        }),
                    Step::make('Confirm')
                        ->schema([
                            Hidden::make('channel_id'),
                            TextInput::make('confirmation_code')
                                ->label('Confirmation code')
                                ->required()
                                // The signing secret is shown on step 2 now, so the
                                // only thing left to say here is where the code went.
                                ->helperText(fn (Get $get): string => $get('channel_type') === 'webhook'
                                    ? 'We just sent a confirmation message to your endpoint; enter the 6-digit code from its "code" field.'
                                    : 'We just sent a 6-digit code to the channel you configured.'),
                        ]),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit">
                            Confirm &amp; connect
                        </x-filament::button>
                    BLADE))),
            ]);
    }

    /**
     * Point step 2 at the newly-chosen channel type.
     *
     * Step 2's schema is built from the picked type, so its fields only come
     * into existence after this request — and Schema::fill() (the only thing
     * that applies a field's ->default()) ran back at mount(), when the step
     * was still empty. Wizard::nextStep() fills the arriving step's paths with
     * NULL, not with defaults. So the defaults are seeded here instead,
     * otherwise every option would open blank and save as null.
     *
     * Every other type's setup fields are cleared first: stepping back and
     * connecting a different type in the same wizard must not leave the
     * previous type's URL or signing secret sitting in the form state.
     */
    private function resetSetupStep(?string $slug, Set $set): void
    {
        $registry = app(ChannelTypeRegistry::class);

        foreach ($registry->all() as $type) {
            foreach ($type->formSchema() as $component) {
                if ($component instanceof Field) {
                    $set($component->getName(), null);
                }
            }
        }

        foreach ($registry->find((string) $slug)?->formSchema() ?? [] as $component) {
            if ($component instanceof Field) {
                $set($component->getName(), $component->getDefaultState());
            }
        }
    }

    public function connect(): void
    {
        // Deliberately not $this->form->getState(): that re-validates every
        // step, including step 1's channel_type — which by now legitimately
        // fails its own disableOptionWhen() check, since the pending channel
        // created after step 2 counts against the very slot that selection
        // used. Steps 1 and 2 already ran their own validation when the
        // subscriber advanced past them; only the confirmation code itself
        // needs validating here.
        $channelId = $this->data['channel_id'] ?? null;
        $code = trim((string) ($this->data['confirmation_code'] ?? ''));

        if (blank($channelId) || blank($code)) {
            Notification::make()
                ->title('Enter the confirmation code')
                ->danger()
                ->send();

            return;
        }

        $channel = NotificationChannel::findOrFail($channelId);

        if ($channel->user_id !== auth()->id()) {
            abort(403);
        }

        $verified = app(VerifyChannelConfirmationCode::class)($channel, $code);

        if (! $verified) {
            Notification::make()
                ->title('That code didn\'t match')
                ->body('Double-check the code and try again — codes expire after 15 minutes.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Channel connected')
            ->success()
            ->send();

        $this->redirect(route('filament.dashboard.resources.notification-channels.index'));
    }
}

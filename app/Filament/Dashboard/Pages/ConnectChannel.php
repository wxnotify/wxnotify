<?php

namespace App\Filament\Dashboard\Pages;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use BackedEnum;
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
use Illuminate\Support\Arr;
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
                                ->live(),
                        ]),
                    Step::make('Set it up')
                        ->schema(fn (Get $get) => $get('channel_type')
                            ? $registry->find($get('channel_type'))?->formSchema() ?? []
                            : [])
                        ->afterValidation(function (Get $get, Set $set) {
                            $type = app(ChannelTypeRegistry::class)->find($get('channel_type'));

                            try {
                                $destination = $type->buildDestination($get());
                            } catch (\InvalidArgumentException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                throw new Halt;
                            }

                            $existing = $this->existingWebPushChannel($type, $destination);
                            $channel = $this->storePendingChannel($type, $destination, $existing);

                            $dispatched = app(DispatchChannelConfirmationCode::class)($channel);

                            if (! $dispatched) {
                                // Delete rather than leave a permanently-pending
                                // channel behind: nothing else can retry it, and
                                // an unqualified retry (clicking Next again)
                                // would otherwise create a duplicate row on
                                // every attempt. A channel that already existed
                                // is only ever refreshed, never deleted — it is
                                // the subscriber's own, previously working one.
                                if ($existing === null) {
                                    $channel->delete();
                                }

                                Notification::make()
                                    ->title('Couldn\'t send your confirmation code')
                                    ->body('The notification service is temporarily unavailable. Please try again in a moment.')
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            $set('channel_id', $channel->id);

                            // Unconditional: stepping back and connecting a different
                            // type in the same wizard must not leave the previous
                            // channel's secret on the Confirm step.
                            $set('webhook_secret', $destination['config']['secret'] ?? null);
                        }),
                    Step::make('Confirm')
                        ->schema([
                            Hidden::make('channel_id'),
                            TextInput::make('webhook_secret')
                                ->label('Signing secret')
                                ->password()
                                ->revealable()
                                ->readOnly()
                                ->dehydrated(false)
                                ->helperText('Verify each request by computing HMAC-SHA256 of the raw request body with this secret and comparing it to the "Signature" header. Copy it now; you can reveal it again from the channel\'s edit form.')
                                ->visible(fn (Get $get): bool => filled($get('webhook_secret'))),
                            TextInput::make('confirmation_code')
                                ->label('Confirmation code')
                                ->required()
                                ->helperText(fn (Get $get): string => filled($get('webhook_secret'))
                                    ? 'We just POSTed a confirmation message to your endpoint; enter the 6-digit code from its "code" field.'
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
     * The subscriber's existing Web Push channel for this exact browser
     * endpoint, if any.
     *
     * The subscribe component deliberately reuses the browser's live
     * PushSubscription rather than creating a second one, so a subscriber who
     * runs the wizard twice in one browser would otherwise end up with two
     * channels pointing at one endpoint and receive every alert twice. Nothing
     * at the database level can catch this: `config` is an encrypted column, so
     * the endpoint is only comparable after decryption.
     *
     * @param  array<string, mixed>  $destination
     */
    private function existingWebPushChannel(ChannelType $type, array $destination): ?NotificationChannel
    {
        $endpoint = $destination['config']['endpoint'] ?? null;

        if ($type->slug() !== 'webpush' || ! is_string($endpoint)) {
            return null;
        }

        return NotificationChannel::query()
            ->where('user_id', auth()->id())
            ->where('channel_type', 'webpush')
            ->get()
            ->first(fn (NotificationChannel $channel): bool => ($channel->config['endpoint'] ?? null) === $endpoint);
    }

    /**
     * Refresh the given channel with the newly-built destination, or create one
     * when there is nothing to refresh. A refreshed channel goes back to
     * unverified: its keys just changed, and the subscriber has to confirm the
     * new code before it delivers again.
     *
     * @param  array<string, mixed>  $destination
     */
    private function storePendingChannel(ChannelType $type, array $destination, ?NotificationChannel $existing): NotificationChannel
    {
        $attributes = [
            'label' => $destination['label'] ?? $type->label(),
            ...Arr::only($destination, ['apprise_url', 'config']),
        ];

        if ($existing !== null) {
            $existing->forceFill([...$attributes, 'verified_at' => null])->save();

            return $existing;
        }

        return NotificationChannel::create([
            'user_id' => auth()->id(),
            'channel_type' => $type->slug(),
            ...$attributes,
        ]);
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

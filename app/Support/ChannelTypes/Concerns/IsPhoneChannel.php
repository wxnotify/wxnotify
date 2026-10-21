<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Rules\NanpPhoneNumber;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use InvalidArgumentException;

/**
 * The shared shape of a PhoneChannelType: both numbers and the consent box on the form,
 * credentials plus the numbers and consent record in `config`, a masked number as the
 * label and summary, and the fixed behaviour every phone type shares (dashboard-only,
 * no free slot, plain text).
 */
trait IsPhoneChannel
{
    /**
     * The provider credentials to keep in `config`, from the guided form's data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract protected function credentialConfig(array $data): array;

    /**
     * Whether this type calls the number rather than texting it (the consent wording).
     */
    protected function placesCalls(): bool
    {
        return false;
    }

    /**
     * The From number, the subscriber's own number and the consent box, which end every
     * phone type's form after its credential fields. The numbers use `type('tel')`, not
     * `tel()`: Filament's `tel()` adds a regex that refuses `+1 (555) 234-5678`, so
     * NanpPhoneNumber must stay their only format check.
     *
     * @return array<Component>
     */
    protected function phoneFields(string $fromHelp): array
    {
        return [
            TextInput::make('from')
                ->label('From number')
                ->type('tel')
                ->required()
                ->rules([new NanpPhoneNumber])
                ->helperText($fromHelp),
            TextInput::make('to')
                ->label('Your number')
                ->type('tel')
                ->required()
                ->rules([new NanpPhoneNumber])
                ->helperText('The US or Canadian number to send alerts to. We send a code to it to confirm it is yours.'),
            Checkbox::make('consent')
                ->label(PhoneConsent::text($this->providerLabel(), $this->placesCalls()))
                ->accepted(),
        ];
    }

    /**
     * Both numbers in E.164 and the consent record.
     *
     * @param  array<string, mixed>  $data
     * @return array{from: string, to: string, consent_version: string, consented_at: string}
     *
     * @throws InvalidArgumentException when a number cannot be normalised or consent was not given
     */
    protected function phoneConfig(array $data): array
    {
        $from = PhoneNumber::normalize((string) ($data['from'] ?? ''));
        $to = PhoneNumber::normalize((string) ($data['to'] ?? ''));

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('A phone channel needs a US or Canadian From and To number.');
        }

        if (! filter_var($data['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException('A phone channel needs the subscriber\'s consent.');
        }

        return [
            'from' => $from,
            'to' => $to,
            'consent_version' => PhoneConsent::VERSION,
            'consented_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{config: array<string, mixed>, label: string}
     */
    public function buildDestination(array $data): array
    {
        $phone = $this->phoneConfig($data);

        return [
            'config' => [...$this->credentialConfig($data), ...$phone],
            'label' => $this->label().' '.PhoneNumber::mask($phone['to']),
        ];
    }

    /**
     * The alert form's inline create bypasses BeginChannelConnection and has no consent box.
     */
    public function supportsInlineCreate(): bool
    {
        return false;
    }

    /**
     * The app cannot show the consent box or this many fields; phone channels are created
     * on the dashboard and then appear in the app.
     *
     * @return null
     */
    public function appSetupFields(User $user): ?array
    {
        return null;
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * Phone channels are gated by the catalog's `min_tier` instead.
     */
    public function countsTowardFreeSlot(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        $to = $record->config['to'] ?? null;

        return is_string($to) && $to !== '' ? PhoneNumber::mask($to) : '—';
    }
}

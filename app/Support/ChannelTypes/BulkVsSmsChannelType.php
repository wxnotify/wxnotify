<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\SmsChannelType;
use App\Support\PhoneNumber;
use Filament\Forms\Components\TextInput;

/**
 * Texts the subscriber's own number through their own BulkVS account, via Apprise's `bulkvs://`
 * plugin.
 *
 * Checked against Apprise 2.0.0 (no network): `bulkvs://{user}:{password}@15552340001/15555550123`
 * parses to the from host `15552340001` and the target `15555550123`, and a username holding `@`
 * and a password holding `:/?#%+` and a space, percent-encoded with `rawurlencode`, come back
 * from the parser as the original values, so the encoding round-trips. When BulkVS answers
 * anything but HTTP 200 the plugin returns False and the Apprise API answers 424, which
 * `AppriseApiClient::notify()` reports as not delivered; the transport then records only
 * `failureMessage()`, so a bad number and bad credentials look the same here.
 *
 * Opt-out: BulkVS's STOP handling is UNKNOWN. It could not be learned without the network and
 * Apprise's plugin does not say, so `optOutHint()` is null (the confirmation text makes no
 * promise) and the owner's legal review must find out whether BulkVS honours STOP on the
 * subscriber's number.
 */
class BulkVsSmsChannelType implements SmsChannelType
{
    use IsPhoneChannel;

    public function slug(): string
    {
        return 'bulkvs_sms';
    }

    public function label(): string
    {
        return 'BulkVS SMS';
    }

    public function providerLabel(): string
    {
        return 'BulkVS';
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-ellipsis';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('api_username')
                ->label('API username')
                ->required()
                ->maxLength(255)
                ->helperText('From the BulkVS portal (portal.bulkvs.com), under API credentials.'),
            TextInput::make('api_password')
                ->label('API password')
                ->required()
                ->password()
                ->revealable()
                ->maxLength(255)
                ->helperText('The API password shown next to the username in the BulkVS portal.'),
            ...$this->phoneFields('The From number must be an SMS-enabled BulkVS number on your account.'),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Sms;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{api_username: string, api_password: string}
     */
    protected function credentialConfig(array $data): array
    {
        return [
            'api_username' => trim((string) ($data['api_username'] ?? '')),
            'api_password' => trim((string) ($data['api_password'] ?? '')),
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['api_username', 'api_password', 'from', 'to'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $user = rawurlencode((string) ($config['api_username'] ?? ''));
        $password = rawurlencode((string) ($config['api_password'] ?? ''));
        $from = PhoneNumber::digits((string) ($config['from'] ?? ''));
        $to = PhoneNumber::digits((string) ($config['to'] ?? ''));

        return "bulkvs://{$user}:{$password}@{$from}/{$to}";
    }

    public function failureMessage(): string
    {
        return 'BulkVS did not accept the text message. Check the API username and password and the From number.';
    }

    public function optOutHint(): ?string
    {
        return null;
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just texted a 6-digit code to '.$this->displaySummary($channel).'.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t send the code. Check your BulkVS API username and password and both numbers, then try again.';
    }

    public function destinationLabel(): string
    {
        return 'Text number';
    }
}

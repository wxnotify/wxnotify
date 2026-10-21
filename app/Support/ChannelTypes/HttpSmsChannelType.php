<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\SmsChannelType;
use App\Support\PhoneNumber;
use Filament\Forms\Components\TextInput;

/**
 * Texts the subscriber's number from their own Android phone, used as an SMS gateway by the
 * httpSMS app, via Apprise's `httpsms://` plugin.
 *
 * Checked against Apprise 2.0.0 (no network): `httpsms://{key}@15552340001/15555550123` parses to
 * the source `15552340001` and the target `15555550123`; a key holding `_`, `-` and a
 * percent-encoded `%` and `/` (`rawurlencode`d) comes back from the parser as the original key.
 * A target equal to the source is accepted. The plugin returns False on any non-200 from
 * httpSMS, which the Apprise API turns into a 424; `AppriseApiClient::notify()` reports that as
 * not delivered and the transport records only `failureMessage()`, so a bad key and a rejected
 * number look the same here.
 *
 * Opt-out: httpSMS has no STOP handling of its own. The message leaves the subscriber's own
 * phone as an ordinary text, so any reply lands in that phone's messages and nothing on our
 * side or httpSMS's can act on it. `optOutHint()` is therefore null; the confirmation text makes
 * no STOP promise.
 */
class HttpSmsChannelType implements SmsChannelType
{
    use IsPhoneChannel;

    public function slug(): string
    {
        return 'httpsms';
    }

    public function label(): string
    {
        return 'httpSMS (your Android phone)';
    }

    public function providerLabel(): string
    {
        return 'httpSMS';
    }

    public function icon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('api_key')
                ->label('API key')
                ->required()
                ->password()
                ->revealable()
                ->maxLength(255)
                ->helperText('The key from your httpSMS account settings. It lets us send texts through your own Android phone running the httpSMS app.'),
            ...$this->phoneFields('The number of the Android phone running the httpSMS app.'),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Sms;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{api_key: string}
     */
    protected function credentialConfig(array $data): array
    {
        return ['api_key' => trim((string) ($data['api_key'] ?? ''))];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['api_key', 'from', 'to'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $key = rawurlencode((string) ($config['api_key'] ?? ''));
        $from = PhoneNumber::digits((string) ($config['from'] ?? ''));
        $to = PhoneNumber::digits((string) ($config['to'] ?? ''));

        return "httpsms://{$key}@{$from}/{$to}";
    }

    public function failureMessage(): string
    {
        return 'httpSMS did not accept the text message. Check the API key, that the httpSMS app is running on your phone, and the From number.';
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
        return 'We couldn\'t send the code. Check your httpSMS API key, that the httpSMS app is running on your phone, and both numbers, then try again.';
    }

    public function destinationLabel(): string
    {
        return 'Text number';
    }
}

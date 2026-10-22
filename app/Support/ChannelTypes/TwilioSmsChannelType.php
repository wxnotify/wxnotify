<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\SmsChannelType;
use App\Support\PhoneNumber;
use Filament\Forms\Components\TextInput;

/**
 * Texts the subscriber's own number through their own Twilio account, via Apprise's
 * `twilio://` plugin.
 *
 * Checked against Apprise 2.0.0 (no network): `twilio://{sid}:{token}@15552340001/15555550123`
 * parses to source `+15552340001` and target `+15555550123`, and so do a `+` or `%2B` in the
 * host and `?from=15552340001`; a ten-digit host without the leading 1 is refused (the plugin
 * wants 11-14 digits or a short code), so the URL always carries `PhoneNumber::digits()`.
 * When Twilio answers anything but 200/201 the plugin returns False and the Apprise API answers
 * 424, which `AppriseApiClient::notify()` reports as not delivered; the transport then records
 * only `failureMessage()`.
 */
class TwilioSmsChannelType implements SmsChannelType
{
    use IsPhoneChannel;

    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-fA-F]{32}$/';

    public const AUTH_TOKEN_PATTERN = '/^[0-9a-fA-F]{32}$/';

    public function slug(): string
    {
        return 'twilio_sms';
    }

    public function label(): string
    {
        return 'Twilio SMS';
    }

    public function providerLabel(): string
    {
        return 'Twilio';
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-ellipsis';
    }

    public function formSchema(): array
    {
        return [
            ...self::credentialFields(),
            ...$this->phoneFields('An SMS-capable number on your Twilio account. US carriers require A2P 10DLC registration for it (or a verified toll-free number), and a trial account can only text numbers you have verified in Twilio.'),
        ];
    }

    /**
     * The Account SID and auth token fields, shared with the Twilio voice type so both validate
     * the same way.
     *
     * @return array<TextInput>
     */
    public static function credentialFields(): array
    {
        return [
            TextInput::make('account_sid')
                ->label('Account SID')
                ->required()
                ->regex(self::ACCOUNT_SID_PATTERN)
                ->validationMessages(['regex' => 'A Twilio Account SID starts with AC, followed by 32 letters and numbers.'])
                ->helperText('Shown under Account Info on your Twilio Console dashboard (console.twilio.com).'),
            TextInput::make('auth_token')
                ->label('Auth token')
                ->required()
                ->password()
                ->revealable()
                ->regex(self::AUTH_TOKEN_PATTERN)
                ->validationMessages(['regex' => 'A Twilio auth token is 32 letters and numbers.'])
                ->helperText('Next to the Account SID under Account Info in the Twilio Console.'),
        ];
    }

    /**
     * The Account SID and auth token to keep in `config`, from the guided form's data.
     *
     * @param  array<string, mixed>  $data
     * @return array{account_sid: string, auth_token: string}
     */
    public static function credentialsFrom(array $data): array
    {
        return [
            'account_sid' => trim((string) ($data['account_sid'] ?? '')),
            'auth_token' => trim((string) ($data['auth_token'] ?? '')),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Sms;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account_sid: string, auth_token: string}
     */
    protected function credentialConfig(array $data): array
    {
        return self::credentialsFrom($data);
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['account_sid', 'auth_token', 'from', 'to'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $sid = rawurlencode((string) ($config['account_sid'] ?? ''));
        $token = rawurlencode((string) ($config['auth_token'] ?? ''));
        $from = PhoneNumber::digits((string) ($config['from'] ?? ''));
        $to = PhoneNumber::digits((string) ($config['to'] ?? ''));

        return "twilio://{$sid}:{$token}@{$from}/{$to}";
    }

    public function failureMessage(): string
    {
        return 'Twilio did not accept the text message. Check the Account SID, auth token and From number, and that your number can receive texts.';
    }

    public function optOutHint(): ?string
    {
        return 'Reply STOP to opt out.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just texted a 6-digit code to '.$this->displaySummary($channel).'.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t send the code. Check your Twilio credentials and both numbers, then try again.';
    }

    public function destinationLabel(): string
    {
        return 'Text number';
    }
}

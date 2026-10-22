<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;

/**
 * Calls the subscriber's own number through their own Twilio account and reads the alert out
 * (TwilioVoiceTransport, directly against Twilio's API, one attempt per send; VoiceScript builds
 * what is said). The confirmation code is read out in a call too, so confirming proves the line
 * takes calls.
 *
 * A separate type from Twilio SMS so a rule can pick calls only for its worst alerts, and so its
 * confirmation, deliveries and failures are its own. The credential fields and their checks are
 * Twilio SMS's (`TwilioSmsChannelType::credentialFields()`), typed again here.
 */
class TwilioVoiceChannelType implements PhoneChannelType
{
    use IsPhoneChannel;

    public function slug(): string
    {
        return 'twilio_voice';
    }

    public function label(): string
    {
        return 'Twilio voice call';
    }

    public function providerLabel(): string
    {
        return 'Twilio';
    }

    public function icon(): string
    {
        return 'heroicon-o-phone';
    }

    public function formSchema(): array
    {
        return [
            ...TwilioSmsChannelType::credentialFields(),
            ...$this->phoneFields('A voice-capable number on your Twilio account. Each alert is a billed call on your Twilio account, and a trial account can only call numbers you have verified in Twilio.'),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::TwilioVoice;
    }

    protected function placesCalls(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account_sid: string, auth_token: string}
     */
    protected function credentialConfig(array $data): array
    {
        return TwilioSmsChannelType::credentialsFrom($data);
    }

    /**
     * The general text; a refused call records TwilioVoiceTransport's more specific fixed text.
     */
    public function failureMessage(): string
    {
        return 'Twilio did not place the call. Check the Account SID, auth token and From number, and that your number can take calls.';
    }

    /**
     * A call has no reply keywords: deleting the channel stops the calls.
     */
    public function optOutHint(): ?string
    {
        return null;
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We\'re calling '.$this->displaySummary($channel).' now. Answer and listen for the 6-digit code.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t place the call. Check your Twilio credentials and both numbers, then try again.';
    }

    public function destinationLabel(): string
    {
        return 'Call number';
    }
}

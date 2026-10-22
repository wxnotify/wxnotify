<?php

namespace App\Support\ChannelTypes;

use App\Models\NotificationChannel;
use App\Rules\InternationalPhoneNumber;
use App\Support\ChannelTypes\Concerns\IsMessagingChannel;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\Delivery\MessagingMessage;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Sends WhatsApp messages to the subscriber's own number through their own WhatsApp Business
 * account on Meta's Cloud API, via Apprise's `whatsapp://` plugin, always as the one approved
 * Utility template they name, with the whole WxNotify text as its one parameter `{{1}}`.
 *
 * Alerts are business-initiated, so they arrive outside any 24-hour customer-service window,
 * where WhatsApp delivers only an approved template. Twilio's route was rejected for that reason:
 * Apprise's `plugins/twilio.py` in WhatsApp mode (`twilio://SID:TOKEN@w%3A1555…/1555…`) posts a
 * free-form `Body` only, with no Content SID or template, which Twilio refuses outside a window.
 *
 * Checked against Apprise 2.0.0 with `requests.post` monkeypatched and every other connection
 * refused (no network):
 * - `whatsapp://wx_alert:{token}@123456789012/525512345678?lang=es_MX&:body=1` posts once, to
 *   `https://graph.facebook.com/v21.0/123456789012/messages` only, with `Authorization: Bearer
 *   {token}` and `{"messaging_product":"whatsapp","to":"+525512345678","recipient_type":
 *   "individual","type":"template","template":{"name":"wx_alert","language":{"code":"es_MX"},
 *   "components":[{"type":"body","parameters":[{"type":"text","text":<the body>}]}]}}`.
 * - The mapping must be `:body=1` (map the body into parameter 1). The `:1=body` the design names
 *   sends the literal word "body" as the parameter; `%3Abody=1` parses the same as the literal
 *   form used here. With no mapping there are no components, which Meta refuses for a template
 *   with a variable.
 * - The target is digits only (`PhoneNumber::digits()`); Apprise adds the `+`. A raw `+` or `%2B`
 *   gives the same `to`. Apprise drops a target under 10 or over 14 digits (a 16+ digit one would
 *   be a group), so normalizeNumber() refuses those (8, 9 and 15 digits pass normalizeInternational()).
 * - Apprise passes the body through unchanged (newlines and tabs too), so MessagingMessage folds
 *   it to one line; a title would be joined on with `\r\n`, so none is sent.
 * - Without `lang` the language is `en_US`. A non-200 reply from Meta makes the plugin return
 *   False, so the Apprise API answers 424 and the transport records only failureMessage().
 * - Apprise refuses a token or phone ID holding anything but letters and digits, and a template
 *   name holding a space, but it decodes and sends a template name with `/ @ : ? # % + & = é`.
 *   Meta's own names are `[a-z0-9_]`, so every part is allow-listed here (refused, never encoded
 *   into a different value) and rawurlencode() changes none of them.
 */
class WhatsAppChannelType implements MessagingChannelType, PhoneChannelType
{
    use IsMessagingChannel, IsPhoneChannel {
        IsPhoneChannel::buildDestination insteadof IsMessagingChannel;
        IsPhoneChannel::supportsInlineCreate insteadof IsMessagingChannel;
        IsPhoneChannel::appSetupFields insteadof IsMessagingChannel;
        IsPhoneChannel::supportsRichContent insteadof IsMessagingChannel;
        IsPhoneChannel::countsTowardFreeSlot insteadof IsMessagingChannel;
        IsPhoneChannel::isAvailable insteadof IsMessagingChannel;
    }

    public const NUMBER_INVALID_MESSAGE = 'Enter the WhatsApp number with its country code, 10 to 14 digits, e.g. +52 55 1234 5678.';

    public const ACCESS_TOKEN_PATTERN = '/^[A-Za-z0-9]{1,1024}$/D';

    public const PHONE_NUMBER_ID_PATTERN = '/^\d{6,20}$/D';

    public const TEMPLATE_PATTERN = '/^[a-z0-9_]{1,512}$/D';

    /**
     * The template languages offered, as Meta's language codes.
     */
    public const LANGUAGES = [
        'en_US' => 'English (US)',
        'en' => 'English',
        'es' => 'Spanish',
        'es_MX' => 'Spanish (Mexico)',
    ];

    public function slug(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp';
    }

    public function providerLabel(): string
    {
        return 'Meta WhatsApp';
    }

    public function icon(): string
    {
        return 'fab-whatsapp';
    }

    public function formSchema(): array
    {
        return [
            TextInput::make('access_token')
                ->label('Access token')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(1024)
                ->regex(self::ACCESS_TOKEN_PATTERN)
                ->validationMessages(['regex' => 'A WhatsApp access token is letters and numbers only.'])
                ->helperText('A permanent access token for a System User with the whatsapp_business_messaging permission: Meta Business Settings → System users → Generate token. Anyone with it can message as your business, so we keep it encrypted and never show it again.'),
            TextInput::make('phone_number_id')
                ->label('Phone number ID')
                ->required()
                ->regex(self::PHONE_NUMBER_ID_PATTERN)
                ->validationMessages(['regex' => 'The phone number ID is 6 to 20 digits, not the phone number itself.'])
                ->helperText('The Phone number ID of your business number (not the number itself), under WhatsApp → API Setup in your Meta app. Meta bills your account for each template message. A test number can only message the recipients registered on it.'),
            TextInput::make('template')
                ->label('Template name')
                ->required()
                ->regex(self::TEMPLATE_PATTERN)
                ->validationMessages(['regex' => 'A template name is lower-case letters, numbers and underscores, up to 512 characters.'])
                ->helperText('In WhatsApp Manager, create one template in the Utility category, in the language you pick below, whose body has exactly one variable, not at the very start or end, for example: WxNotify: {{1}} (from your WxNotify account). Meta must approve the template before anything can be sent. Business-initiated messages need it: outside a conversation the person started, WhatsApp delivers only an approved template. Every alert, code and test is sent as this template with the whole WxNotify text in {{1}}, as one line of up to 900 characters.'),
            Select::make('template_language')
                ->label('Template language')
                ->options(self::LANGUAGES)
                ->default('en_US')
                ->selectablePlaceholder(false)
                ->required()
                ->helperText('The language the template was approved in. It must match exactly.'),
            ...$this->phoneFields(''),
        ];
    }

    protected function usesFromNumber(): bool
    {
        return false;
    }

    /**
     * The number in E.164 when normalizeInternational() takes it and it has 10 to 14 digits with its
     * country code: Apprise 2.0.0 drops a WhatsApp target under 10 or over 14 digits, so such a
     * number could never get its code.
     */
    protected function normalizeNumber(string $input): ?string
    {
        $e164 = PhoneNumber::normalizeInternational($input);

        return $e164 !== null && preg_match('/^\+\d{10,14}$/D', $e164) === 1 ? $e164 : null;
    }

    protected function toNumberRule(): ValidationRule
    {
        return new InternationalPhoneNumber($this->normalizeNumber(...), self::NUMBER_INVALID_MESSAGE);
    }

    protected function toNumberHelp(): string
    {
        return 'The WhatsApp number to send alerts to; include the country code, e.g. +52 55 1234 5678. A number without + or 00 in front is treated as a US or Canadian number. It must have opted in to messages from your WhatsApp Business account, and we send a code to it on WhatsApp to confirm it is yours.';
    }

    protected function consentText(): string
    {
        return PhoneConsent::whatsappText();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{access_token: string, phone_number_id: string, template: string, template_language: string}
     *
     * @throws InvalidArgumentException when a credential is not in its allow-list
     */
    protected function credentialConfig(array $data): array
    {
        $credentials = [
            'access_token' => trim((string) ($data['access_token'] ?? '')),
            'phone_number_id' => trim((string) ($data['phone_number_id'] ?? '')),
            'template' => trim((string) ($data['template'] ?? '')),
            'template_language' => (string) ($data['template_language'] ?? ''),
        ];

        if (! self::credentialsAllowed($credentials)) {
            throw new InvalidArgumentException('A WhatsApp channel needs a valid access token, phone number ID, template name and language.');
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function configFromForm(array $data): array
    {
        return $this->buildDestination($data)['config'];
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return ['access_token', 'phone_number_id', 'template', 'template_language', 'to'];
    }

    /**
     * The template URL, or an empty string (which MessagingTransport::send() refuses) when a stored part
     * is outside its allow-list, so a tampered value can never reach the URL.
     *
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string
    {
        $to = (string) ($config['to'] ?? '');

        if (! self::credentialsAllowed($config) || $this->normalizeNumber($to) !== $to) {
            return '';
        }

        $template = rawurlencode((string) $config['template']);
        $token = rawurlencode((string) $config['access_token']);
        $phoneNumberId = rawurlencode((string) $config['phone_number_id']);
        $language = rawurlencode((string) $config['template_language']);

        return "whatsapp://{$template}:{$token}@{$phoneNumberId}/".PhoneNumber::digits($to)."?lang={$language}&:body=1";
    }

    /**
     * Meta's host is fixed (graph.facebook.com).
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string
    {
        return $this->normalizeNumber((string) ($config['to'] ?? '')) ?? '';
    }

    public function destinationNoun(): string
    {
        return 'number';
    }

    public function maxLength(): int
    {
        return MessagingMessage::WHATSAPP_MAX_LENGTH;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function compose(string $body, ?string $title, array $meta): string
    {
        return MessagingMessage::whatsapp($body, $title, $meta);
    }

    /**
     * WhatsApp has no reply-STOP handling: the subscriber deletes the channel or blocks the
     * business number.
     */
    public function optOutHint(): ?string
    {
        return null;
    }

    /**
     * The country code with the last four digits, so a number read in the wrong country shows.
     */
    public function displaySummary(NotificationChannel $record): string
    {
        $to = $record->config['to'] ?? null;

        return is_string($to) && $to !== '' ? PhoneNumber::maskWithCountryCode($to) : '—';
    }

    public function destinationLabel(): string
    {
        return 'WhatsApp number';
    }

    public function failureMessage(): string
    {
        return 'WhatsApp did not accept the message. Check the access token, phone number ID, that the template name and language match an approved template with one variable, and that your number opted in.';
    }

    public function confirmationHint(NotificationChannel $channel): string
    {
        return 'We just sent a 6-digit code to '.$this->displaySummary($channel).' on WhatsApp.';
    }

    public function codeNotSentHint(): string
    {
        return 'We couldn\'t send the WhatsApp message. Check the access token, phone number ID, and that the template name and language match an approved template with one variable.';
    }

    /**
     * Whether the token, phone number ID, template name and language are each in their allow-list.
     *
     * @param  array<string, mixed>  $values
     */
    private static function credentialsAllowed(array $values): bool
    {
        foreach (['access_token', 'phone_number_id', 'template', 'template_language'] as $key) {
            if (! is_string($values[$key] ?? null)) {
                return false;
            }
        }

        return preg_match(self::ACCESS_TOKEN_PATTERN, $values['access_token']) === 1
            && preg_match(self::PHONE_NUMBER_ID_PATTERN, $values['phone_number_id']) === 1
            && preg_match(self::TEMPLATE_PATTERN, $values['template']) === 1
            && array_key_exists($values['template_language'], self::LANGUAGES);
    }
}

<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Exceptions\ChannelCodeThrottled;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Rules\InternationalPhoneNumber;
use App\Rules\NanpPhoneNumber;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * A minimal phone channel type, standing in for the concrete types later tasks add.
 */
function chanFakePhoneType(): PhoneChannelType
{
    return new class implements PhoneChannelType
    {
        use IsPhoneChannel;

        public function slug(): string
        {
            return 'fake_sms';
        }

        public function label(): string
        {
            return 'Fake SMS';
        }

        public function icon(): string
        {
            return 'heroicon-o-chat-bubble-left-ellipsis';
        }

        public function formSchema(): array
        {
            return $this->phoneFields('The number your Fake account sends from.');
        }

        public function transport(): ChannelTransport
        {
            return ChannelTransport::Apprise;
        }

        public function destinationLabel(): string
        {
            return 'Text number';
        }

        public function providerLabel(): string
        {
            return 'Fake';
        }

        public function failureMessage(): string
        {
            return 'Fake did not accept the text message.';
        }

        public function optOutHint(): ?string
        {
            return null;
        }

        public function confirmationHint(NotificationChannel $channel): string
        {
            return 'We texted a code to your number.';
        }

        public function codeNotSentHint(): string
        {
            return 'Check your Fake credentials.';
        }

        protected function credentialConfig(array $data): array
        {
            return ['api_key' => $data['api_key']];
        }
    };
}

it('normalises common US formats to E.164', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('+15552345678')
        ->and(Validator::make(['to' => $input], ['to' => [new NanpPhoneNumber]])->passes())->toBeTrue();
})->with([
    'ten digits' => '5552345678',
    'one plus ten digits' => '15552345678',
    'plus one with punctuation' => '+1 (555) 234-5678',
    'dotted' => '555.234.5678',
    'spaced with a leading one' => '1 555 234 5678',
]);

it('refuses numbers that are not NANP', function (string $input) {
    $validator = Validator::make(['to' => $input], ['to' => ['required', new NanpPhoneNumber]]);

    expect(PhoneNumber::normalize($input))->toBeNull()
        ->and($validator->fails())->toBeTrue();

    if ($input !== '') {
        expect($validator->errors()->first('to'))->toBe(PhoneNumber::INVALID_MESSAGE);
    }
})->with([
    'seven digits' => '234-5678',
    'a UK number' => '+44 20 7946 0958',
    'area code starting 0' => '055-234-5678',
    'area code starting 1' => '155-234-5678',
    'exchange starting 0' => '555-034-5678',
    'exchange starting 1' => '555-123-4567',
    'letters' => '555-CALL-NOW',
    'an extension' => '555-234-5678 x123',
    'a plus in the middle' => '1+5552345678',
    'a non-1 country code' => '+2 555 234 5678',
    'empty' => '',
]);

it('gives Apprise digits only and masks to the last four', function () {
    expect(PhoneNumber::digits('+15552345678'))->toBe('15552345678')
        ->and(PhoneNumber::mask('+15552345678'))->toBe('•••• 5678');
});

it('words the consent for texts or calls through the named provider', function () {
    expect(PhoneConsent::VERSION)->toBe('2026-10-draft')
        ->and(PhoneConsent::text('Twilio', false))->toContain('Twilio')->toContain('text')->toContain('STOP')
        ->and(PhoneConsent::text('Twilio', true))->toContain('call')->not->toContain('STOP');
});

it('stores both numbers in E.164 with the consent record and a masked label', function () {
    $this->freezeTime();

    $destination = chanFakePhoneType()->buildDestination([
        'api_key' => 'k3y',
        'from' => '(555) 987-6543',
        'to' => '555.234.5678',
        'consent' => true,
    ]);

    expect($destination)->toBe([
        'config' => [
            'api_key' => 'k3y',
            'from' => '+15559876543',
            'to' => '+15552345678',
            'consent_version' => PhoneConsent::VERSION,
            'consented_at' => now()->toIso8601String(),
        ],
        'label' => 'Fake SMS •••• 5678',
    ]);
});

it('refuses to build a destination from a number it cannot normalise or without consent', function (array $data) {
    chanFakePhoneType()->buildDestination([...['api_key' => 'k3y', 'from' => '5559876543', 'to' => '5552345678', 'consent' => true], ...$data]);
})->throws(InvalidArgumentException::class)->with([
    'a bad to' => [['to' => '234-5678']],
    'a bad from' => [['from' => '+44 20 7946 0958']],
    'no consent' => [['consent' => false]],
]);

it('keeps phone types out of the free slot, inline create, the app and rich content', function () {
    $type = chanFakePhoneType();

    expect($type->countsTowardFreeSlot())->toBeFalse()
        ->and($type->supportsInlineCreate())->toBeFalse()
        ->and($type->appSetupFields(User::factory()->create()))->toBeNull()
        ->and($type->supportsRichContent())->toBeFalse()
        ->and($type->isAvailable())->toBeTrue();
});

it('shows only the last four of the number, or a dash without one', function () {
    $type = chanFakePhoneType();

    expect($type->displaySummary(new NotificationChannel(['config' => ['to' => '+15552345678']])))->toBe('•••• 5678')
        ->and($type->displaySummary(new NotificationChannel(['config' => []])))->toBe('—');
});

it('asks for both numbers and a required consent box', function () {
    $fields = collect(chanFakePhoneType()->formSchema())->keyBy(fn ($field) => $field->getName());

    expect($fields->keys()->all())->toBe(['from', 'to', 'consent'])
        ->and($fields['to'])->toBeInstanceOf(TextInput::class)
        ->and($fields['consent'])->toBeInstanceOf(Checkbox::class)
        ->and($fields['consent']->getLabel())->toBe(PhoneConsent::text('Fake', false));
});

it('lets the number fields accept every format the rule accepts and refuse the rest with its message', function (string $field) {
    $rules = collect(chanFakePhoneType()->formSchema())->keyBy(fn ($component) => $component->getName())[$field]->getValidationRules();
    $validate = fn (string $value) => Validator::make([$field => $value], [$field => $rules]);

    foreach (['+1 (555) 234-5678', '1 (555) 234-5678', '(555) 234-5678', '555.234.5678'] as $accepted) {
        expect($validate($accepted)->passes())->toBeTrue("{$field} refused {$accepted}");
    }

    expect($validate('+44 20 7946 0958')->errors()->get($field))->toBe([PhoneNumber::INVALID_MESSAGE]);
})->with(['from', 'to']);

/**
 * A phone type that uses every hook the way WhatsApp will: no From number, international
 * numbers and its own consent wording.
 */
function chanFakeInternationalPhoneType(): PhoneChannelType
{
    return new class implements PhoneChannelType
    {
        use IsPhoneChannel;

        public function slug(): string
        {
            return 'fake_whatsapp';
        }

        public function label(): string
        {
            return 'Fake WhatsApp';
        }

        public function icon(): string
        {
            return 'fab-whatsapp';
        }

        public function formSchema(): array
        {
            return $this->phoneFields('Unused: this type has no From number.');
        }

        public function transport(): ChannelTransport
        {
            return ChannelTransport::Apprise;
        }

        public function destinationLabel(): string
        {
            return 'WhatsApp number';
        }

        public function providerLabel(): string
        {
            return 'Meta';
        }

        public function failureMessage(): string
        {
            return 'Fake WhatsApp did not accept the message.';
        }

        public function optOutHint(): ?string
        {
            return null;
        }

        public function confirmationHint(NotificationChannel $channel): string
        {
            return 'We sent a code to your WhatsApp.';
        }

        public function codeNotSentHint(): string
        {
            return 'Check your Meta credentials.';
        }

        protected function credentialConfig(array $data): array
        {
            return ['token' => $data['token']];
        }

        protected function usesFromNumber(): bool
        {
            return false;
        }

        protected function normalizeNumber(string $input): ?string
        {
            return PhoneNumber::normalizeInternational($input);
        }

        protected function toNumberRule(): ValidationRule
        {
            return new InternationalPhoneNumber;
        }

        protected function consentText(): string
        {
            return PhoneConsent::whatsappText();
        }
    };
}

it('normalises international whatsapp numbers', function (string $input, string $e164) {
    expect(PhoneNumber::normalizeInternational($input))->toBe($e164)
        ->and(Validator::make(['to' => $input], ['to' => [new InternationalPhoneNumber]])->passes())->toBeTrue();
})->with([
    'mexican mobile with the 1' => ['+52 1 55 1234 5678', '+5215512345678'],
    'mexican with 00' => ['0052 55 1234 5678', '+525512345678'],
    'uk mobile' => ['+44 7700 900123', '+447700900123'],
    'dashes and parentheses' => ['+502 (2345) 6789', '+50223456789'],
    'fifteen digits' => ['+234 5678 9012 345', '+23456789012345'],
    'eight digits' => ['+2345 6789', '+23456789'],
    'ten-digit us' => ['(555) 234-5678', '+15552345678'],
    'us through 001' => ['001 555 234 5678', '+15552345678'],
    'italian landline with a leading 0' => ['+39 06 1234 5678', '+390612345678'],
]);

it('refuses numbers that are too short, too long, start +0, or are +1 but not nanp', function (string $input) {
    $validator = Validator::make(['to' => $input], ['to' => ['required', new InternationalPhoneNumber]]);

    expect(PhoneNumber::normalizeInternational($input))->toBeNull()
        ->and($validator->fails())->toBeTrue();

    if ($input !== '') {
        expect($validator->errors()->first('to'))->toBe(PhoneNumber::INTERNATIONAL_INVALID_MESSAGE);
    }
})->with([
    'seven digits' => '234-5678',
    'seven digits after the plus' => '+52 55 123',
    'sixteen digits' => '+52 1234 5678 9012 34',
    'starts +0' => '+0 44 7700 900123',
    'starts 000' => '000 44 7700 900123',
    '+1 with an exchange starting 1' => '+1 555 123 4567',
    '+1 with an area code starting 0' => '+1 055 234 5678',
    '001 with an exchange starting 0' => '001 555 034 5678',
    'eleven non-nanp digits without a plus' => '44770090012',
    'a trunk (0) after +44' => '+44 (0)7700 900123',
    'a trunk (0) after 0044' => '0044 (0) 7700 900123',
    'a trunk (0) after +52' => '+52 (0) 55 1234 5678',
    'a trunk (0) without spaces' => '+44(0)7700900123',
    'a trunk (0) with inner spaces' => '+44 ( 0 ) 7700 900123',
    'fullwidth digits' => '＋４４７７００９００１２３',
    'the us 011 international prefix' => '011 44 7700 900123',
    'letters' => '+52 55 CALL NOW',
    'an extension' => '+44 7700 900123 x12',
    'a plus in the middle' => '52+5512345678',
    'a repeated plus' => '++52 55 1234 5678',
    '00 then a plus' => '00+52 55 1234 5678',
    'empty' => '',
]);

it('normalizeInternational agrees with normalize for every nanp input', function (string $input) {
    expect(PhoneNumber::normalizeInternational($input))->toBe(PhoneNumber::normalize($input));
})->with([
    'ten digits' => '5552345678',
    'one plus ten digits' => '15552345678',
    'plus one with punctuation' => '+1 (555) 234-5678',
    'dotted' => '555.234.5678',
    'spaced with a leading one' => '1 555 234 5678',
    'area code starting 0' => '055-234-5678',
    'area code starting 1' => '155-234-5678',
    'exchange starting 0' => '555-034-5678',
    'exchange starting 1' => '555-123-4567',
    'plus one, exchange starting 1' => '+1 555 123 4567',
]);

it('keeps the nanp message for sms and gives the international rule its own', function () {
    expect(PhoneNumber::INVALID_MESSAGE)->toBe('Enter a US or Canadian number, e.g. (555) 234-5678.')
        ->and(PhoneNumber::INTERNATIONAL_INVALID_MESSAGE)->toBe('Enter your WhatsApp number with its country code, e.g. +52 55 1234 5678 or (555) 234-5678.')
        ->and(Validator::make(['to' => '+44 7700 900123'], ['to' => [new NanpPhoneNumber]])->errors()->first('to'))->toBe(PhoneNumber::INVALID_MESSAGE);
});

it('the phone throttle key for a nanp number is unchanged', function () {
    chanEnablePhoneTypes();
    chanFakePauser();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->for($user)->httpSms('+15552345678')->unverified()->create();
    $key = "phone-code-number:{$user->id}:".hash('sha256', '+15552345678');
    RateLimiter::clear($key);
    RateLimiter::clear("phone-code-user:{$user->id}");

    app(DispatchChannelConfirmationCode::class)($channel);

    expect(DispatchChannelConfirmationCode::numberLimitKey($user->id, '+15552345678'))->toBe($key)
        ->and(RateLimiter::attempts($key))->toBe(1)
        ->and(RateLimiter::attempts("phone-code-user:{$user->id}"))->toBe(1);
});

it('gives an international number its own throttle bucket, whatever its format', function () {
    $user = User::factory()->create();
    $key = DispatchChannelConfirmationCode::numberLimitKey($user->id, '+5215512345678');
    RateLimiter::clear($key);
    RateLimiter::clear(DispatchChannelConfirmationCode::numberLimitKey($user->id, '+447700900123'));
    RateLimiter::clear(DispatchChannelConfirmationCode::userLimitKey($user->id));

    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        RateLimiter::hit($key, 3600);
    }

    DispatchChannelConfirmationCode::refuseWhenThrottled($user->id, '+44 7700 900123');

    expect(fn () => DispatchChannelConfirmationCode::refuseWhenThrottled($user->id, '+52 1 55 1234 5678'))
        ->toThrow(ChannelCodeThrottled::class);
});

it('words the whatsapp consent with the same version, meta charges and how to stop', function () {
    expect(PhoneConsent::whatsappText())
        ->toContain('own this phone number or am allowed to use it')
        ->toContain('opted in')
        ->toContain('WhatsApp Business account')
        ->toContain('Meta')
        ->toContain('deleting this channel')
        ->toContain('blocking the business number in WhatsApp')
        ->not->toContain('STOP');
});

it('leaves out the from number and uses the hooks for the number rule and consent', function () {
    $fields = collect(chanFakeInternationalPhoneType()->formSchema())->keyBy(fn ($field) => $field->getName());
    $rules = $fields['to']->getValidationRules();
    $validate = fn (string $value) => Validator::make(['to' => $value], ['to' => $rules]);

    expect($fields->keys()->all())->toBe(['to', 'consent'])
        ->and($fields['consent']->getLabel())->toBe(PhoneConsent::whatsappText());

    foreach (['+52 1 55 1234 5678', '0052 55 1234 5678', '+44 7700 900123', '(555) 234-5678'] as $accepted) {
        expect($validate($accepted)->passes())->toBeTrue("to refused {$accepted}");
    }

    expect($validate('+1 555 123 4567')->errors()->get('to'))->toBe([PhoneNumber::INTERNATIONAL_INVALID_MESSAGE]);
});

it('stores an international number with no from number', function () {
    $this->freezeTime();

    $destination = chanFakeInternationalPhoneType()->buildDestination([
        'token' => 't0k',
        'from' => '5559876543',
        'to' => '+52 1 55 1234 5678',
        'consent' => true,
    ]);

    expect($destination)->toBe([
        'config' => [
            'token' => 't0k',
            'to' => '+5215512345678',
            'consent_version' => PhoneConsent::VERSION,
            'consented_at' => now()->toIso8601String(),
        ],
        'label' => 'Fake WhatsApp •••• 5678',
    ]);
});

it('refuses to build an international destination from a number it cannot normalise', function () {
    chanFakeInternationalPhoneType()->buildDestination(['token' => 't0k', 'to' => '+52 55 123', 'consent' => true]);
})->throws(InvalidArgumentException::class, 'A phone channel needs a To number it accepts.');

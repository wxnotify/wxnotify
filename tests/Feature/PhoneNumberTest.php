<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Rules\NanpPhoneNumber;
use App\Support\ChannelTypes\Concerns\IsPhoneChannel;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
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
        expect($validator->errors()->first('to'))->toBe('Enter a US or Canadian number, e.g. (555) 234-5678.');
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

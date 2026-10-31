<?php

use App\Enums\MessageBodyStyle;
use App\Enums\OutlookReportMode;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
    $this->location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
});

test('choosing the outlook report shows its three fields and no product table', function () {
    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->assertFormFieldExists('delivery_time')
        ->assertFormFieldExists('report_mode')
        ->assertFormFieldExists('location_ids')
        ->assertFormFieldDoesNotExist('product_settings.weather');
});

test('submitting the outlook report with its defaults saves 14:30, every day and no locations', function () {
    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm(['channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->category->value)->toBe('outlook_report')
        ->and($rule->outlookSendTime())->toBe('14:30')
        ->and($rule->report_mode)->toBe(OutlookReportMode::EveryDay)
        ->and($rule->locations)->toHaveCount(0);
});

test('an outlook report saves the chosen time, mode and locations', function () {
    $second = Location::factory()->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm([
            'delivery_time' => '11:45',
            'report_mode' => 'only_when_forecast',
            'location_ids' => [(string) $this->location->id, (string) $second->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->outlookSendTime())->toBe('11:45')
        ->and($rule->report_mode)->toBe(OutlookReportMode::OnlyWhenForecast)
        ->and($rule->locations->pluck('id')->sort()->values()->all())->toBe(collect([$this->location->id, $second->id])->sort()->values()->all());
});

test('the location list offers only the subscriber\'s own locations that have a fix', function () {
    Location::factory()->for($this->user)->create(['label' => 'Waiting for GPS', 'lat' => null, 'lng' => null]);
    Location::factory()->for(User::factory())->create(['label' => 'Not mine']);

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->assertFormFieldExists('location_ids', fn (CheckboxList $field): bool => $field->getOptions() === [$this->location->id => 'Home']);
});

test('another subscriber\'s location id is refused on an outlook report', function () {
    $someoneElses = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm(['location_ids' => [$someoneElses->id], 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['location_ids.0']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('editing an outlook report loads and saves a changed time, mode and locations', function () {
    $second = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->outlookReport()->create();
    $rule->locations()->attach($this->location);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['delivery_time' => '14:30', 'report_mode' => 'every_day', 'location_ids' => [$this->location->id]])
        ->fillForm(['delivery_time' => '13:15', 'report_mode' => 'only_when_forecast', 'location_ids' => [$second->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $rule->refresh();

    expect($rule->outlookSendTime())->toBe('13:15')
        ->and($rule->report_mode)->toBe(OutlookReportMode::OnlyWhenForecast)
        ->and($rule->locations->pluck('id')->all())->toBe([$second->id]);
});

test('a paid subscriber gets the message length field for an outlook report, with the report\'s own help', function () {
    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->assertFormFieldIsVisible('message_body_style')
        ->assertSee('Brief sends the short circle summary to every channel; Full also sends the detailed email to email channels.');
});

test('an outlook report saved as Brief stores Brief', function () {
    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm(['message_body_style' => 'brief', 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->effectiveMessageBodyStyle())->toBe(MessageBodyStyle::Brief);
});

test('a Free subscriber sees the outlook report as a paid feature and cannot create one', function () {
    $this->user->forceFill(['tier_override' => null])->save();

    livewire(CreateAlertRule::class)
        ->assertSee('Available on paid plans.')
        ->set('data.category', 'outlook_report')
        ->fillForm(['channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['category' => 'in']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('a Pro subscriber can create an outlook report', function () {
    $this->user->forceFill(['tier_override' => Tier::Pro])->save();

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm(['channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->category->value)->toBe('outlook_report');
});

test('a send time after 22:45 UTC is refused and 22:45 is accepted', function (string $time, bool $accepted) {
    $component = livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->fillForm(['delivery_time' => $time, 'channel_ids' => [$this->channel->id]])
        ->call('create');

    if ($accepted) {
        $component->assertHasNoFormErrors();
        expect(AlertRule::where('user_id', $this->user->id)->sole()->outlookSendTime())->toBe($time);
    } else {
        $component->assertHasFormErrors(['delivery_time' => 'before_or_equal']);
        expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
    }
})->with([
    '22:45' => ['22:45', true],
    '23:00' => ['23:00', false],
    '23:45' => ['23:45', false],
]);

test('the send time hint shows the UTC time in the subscriber\'s own timezone', function () {
    $this->user->update(['timezone' => 'America/Phoenix']);

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->set('data.delivery_time', '14:30')
        ->assertSee('That is 7:30 AM your time.')
        ->set('data.delivery_time', '22:45')
        ->assertSee('That is 3:45 PM your time.');
});

test('without a profile timezone the send time hint is left out', function () {
    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->set('data.delivery_time', '14:30')
        ->assertDontSee('your time.')
        ->assertSee('The latest send time is 22:45 UTC');
});

test('a downgraded owner saving a kept outlook report on Edit is told it is a paid feature', function () {
    $rule = AlertRule::factory()->outlookReport()->for($this->user)->create(['name' => 'Chase report']);
    $rule->notificationChannels()->attach($this->channel);
    $this->user->forceFill(['tier_override' => null])->save();

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasFormErrors(['category'])
        ->assertSee('Available on paid plans.')
        ->assertDontSee('The selected category is invalid.');

    expect($rule->fresh()->name)->toBe('Chase report');
});

test('a downgraded owner can still pause and delete a kept outlook report from the list', function () {
    $rule = AlertRule::factory()->outlookReport()->for($this->user)->create();
    $other = AlertRule::factory()->outlookReport()->for($this->user)->create();
    $this->user->forceFill(['tier_override' => null])->save();

    livewire(ListAlertRules::class)
        ->call('updateTableColumnState', 'is_active', (string) $rule->id, false)
        ->callAction(TestAction::make('delete')->table($other));

    expect($rule->fresh()->is_active)->toBeFalse()
        ->and(AlertRule::find($other->id))->toBeNull();
});

test('a SaveAlertRule refusal reached from the form is shown on the category field', function () {
    expect(fn () => AlertRuleForm::saveForForm(fn () => throw ValidationException::withMessages(['category' => 'Available on paid plans.'])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['data.category' => ['Available on paid plans.']]));
});

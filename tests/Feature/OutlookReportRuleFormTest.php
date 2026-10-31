<?php

use App\Enums\OutlookReportMode;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Forms\Components\CheckboxList;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
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

test('the message length field is not offered for an outlook report', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->assertFormFieldIsHidden('message_body_style');
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

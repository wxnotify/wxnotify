<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->sounds = ['short_fuse_warning' => 'alert', 'other' => 'bell'];
});

function soundsCall($test, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->json($method, $uri, $body);
}

function soundsBody($test, array $extra = []): array
{
    return [
        'category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'nationwide',
        'channel_ids' => [$test->channel->id], ...$extra,
    ];
}

test('a rule created with push sounds stores them and the resource returns them', function () {
    $id = soundsCall($this, 'POST', '/api/v1/alert-rules', soundsBody($this, ['push_sounds' => $this->sounds]))
        ->assertCreated()
        ->assertJsonPath('data.push_sounds', $this->sounds)
        ->json('data.id');

    expect(AlertRule::findOrFail($id)->push_sounds)->toBe($this->sounds);
});

test('a rule without push sounds returns an empty object', function () {
    $response = soundsCall($this, 'POST', '/api/v1/alert-rules', soundsBody($this))->assertCreated();

    expect($response->json('data.push_sounds'))->toBe([])
        ->and($response->getContent())->toContain('"push_sounds":{}');
});

test('an update without the key keeps the stored sounds', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['push_sounds' => $this->sounds]);

    soundsCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", soundsBody($this))->assertOk();

    expect($rule->fresh()->push_sounds)->toBe($this->sounds);
});

test('an update with an empty object or null clears the stored sounds', function (mixed $value) {
    $rule = AlertRule::factory()->for($this->user)->create(['push_sounds' => $this->sounds]);

    soundsCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", soundsBody($this, ['push_sounds' => $value]))
        ->assertOk()
        ->assertJsonPath('data.push_sounds', []);

    expect($rule->fresh()->push_sounds)->toBeNull();
})->with([[[]], [null]]);

test('a null value for a kind is accepted and dropped while a valid sibling is stored', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['push_sounds' => $this->sounds]);

    soundsCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", soundsBody($this, ['push_sounds' => ['other' => null, 'advisory' => 'harp']]))->assertOk();

    expect($rule->fresh()->push_sounds)->toBe(['advisory' => 'harp']);
});

test('an update replaces the stored sounds', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['push_sounds' => $this->sounds]);

    soundsCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", soundsBody($this, ['push_sounds' => ['advisory' => 'harp']]))->assertOk();

    expect($rule->fresh()->push_sounds)->toBe(['advisory' => 'harp']);
});

test('invalid push sounds are refused and nothing is written', function (mixed $value, string $field) {
    soundsCall($this, 'POST', '/api/v1/alert-rules', soundsBody($this, ['push_sounds' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(AlertRule::count())->toBe(0);
})->with([
    'unknown kind' => [['loud' => 'alert'], 'push_sounds'],
    'unknown slug' => [['other' => 'nope'], 'push_sounds.other'],
    'path-like slug' => [['other' => '../x'], 'push_sounds.other'],
    'not an array' => ['alert', 'push_sounds'],
]);

test('a Free subscriber can set push sounds', function () {
    expect($this->user->tier())->toBe(Tier::Free);

    soundsCall($this, 'POST', '/api/v1/alert-rules', soundsBody($this, ['push_sounds' => $this->sounds]))->assertCreated();
});

test('a dashboard save keeps the stored push sounds', function () {
    $this->actingAs($this->user);
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['push_sounds' => $this->sounds]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->push_sounds)->toBe($this->sounds);
});

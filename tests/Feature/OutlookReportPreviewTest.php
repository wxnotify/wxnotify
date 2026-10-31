<?php

use App\Actions\Weather\FetchSpcOutlooks;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\OutlookReportPreview;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

const ORP_BASE = 'www.spc.noaa.gov/products/';

/**
 * Every SPC outlook file: Day 1's category layer carries `$day1Cat` over a box around Oklahoma City, the rest is quiet.
 *
 * @return array<string, mixed>
 */
function orpFiles(array $day1Cat = ['TSTM', 'SLGT']): array
{
    $collection = fn (array $labels): string => json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-98.0, 35.0], [-96.0, 35.0], [-96.0, 37.0], [-98.0, 37.0], [-98.0, 35.0]]]],
            'properties' => ['LABEL' => $label, 'ISSUE_ISO' => '2026-10-31T13:00:00Z'],
        ], $labels),
    ]);
    $headers = ['Content-Type' => 'application/geo+json'];
    $files = [ORP_BASE.'outlook/day1otlk_cat.nolyr.geojson' => Http::response($collection($day1Cat), 200, $headers)];

    foreach ([1, 2] as $day) {
        foreach (['cat', 'torn', 'hail', 'wind'] as $layer) {
            $files[ORP_BASE."outlook/day{$day}otlk_{$layer}.nolyr.geojson"] ??= Http::response($collection([]), 200, $headers);
        }
    }

    $files[ORP_BASE.'outlook/day3otlk_cat.nolyr.geojson'] = Http::response($collection([]), 200, $headers);
    $files[ORP_BASE.'outlook/day3otlk_prob.nolyr.geojson'] = Http::response($collection([]), 200, $headers);

    foreach ([4, 5, 6, 7, 8] as $day) {
        $files[ORP_BASE."exper/day4-8/day{$day}prob.nolyr.geojson"] = Http::response($collection([]), 200, $headers);
    }

    return $files;
}

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus, 'timezone' => 'America/Denver']);
    $this->actingAs($this->user);
    $this->home = Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 35.9, 'lng' => -97.0]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the outlook form starts with a collapsed Today\'s Report section above the send time and asks SPC nothing', function () {
    $this->seed(ProductCatalogSeeder::class);
    Http::fake(orpFiles());

    livewire(CreateAlertRule::class)
        ->set('data.category', 'outlook_report')
        ->assertSchemaComponentExists('outlookReportPreview', checkComponentUsing: fn (Section $section): bool => $section->isCollapsed() && $section->getHeading() === 'Today\'s Report')
        ->assertSeeInOrder(['Today&#039;s Report', 'Send time'], escape: false);

    Http::assertNothingSent();
});

test('the preview asks SPC nothing until it is opened', function () {
    Http::fake(orpFiles());

    livewire(OutlookReportPreview::class)
        ->assertSeeText('Loading today\'s report')
        ->assertDontSeeText('Day 1 -');

    Http::assertNothingSent();
});

test('opening the preview fetches SPC once and shows the Full report for the ticked locations with SPC\'s own images', function () {
    Carbon::setTestNow('2026-10-31 20:00:00 UTC');
    Http::fake(orpFiles());

    livewire(OutlookReportPreview::class)
        ->call('load', [(string) $this->home->id])
        ->assertSeeText('Day 1 - 🟡 Slight')
        ->assertSee('October 31, 2026')
        ->assertSee('Home</td><td style="padding: 3px 8px 3px 0; white-space: nowrap;">🟡 Slight</td>', escape: false)
        ->assertSee('src="https://www.spc.noaa.gov/products/outlook/day1otlk.png"', escape: false)
        ->assertDontSee('cid:', escape: false)
        ->call('load', [(string) $this->home->id]);

    Http::assertSentCount(15);
});

test('unticking every location leaves only the US-wide report', function () {
    Http::fake(orpFiles());

    livewire(OutlookReportPreview::class)
        ->call('load', [(string) $this->home->id])
        ->assertSee('Home')
        ->call('load', [])
        ->assertDontSee('Home')
        ->assertSeeText('Day 1 - 🟡 Slight');
});

test('another subscriber\'s location id never reaches the preview', function () {
    Http::fake(orpFiles());
    $notMine = Location::factory()->for(User::factory())->create(['label' => 'Not mine', 'lat' => 35.9, 'lng' => -97.0]);

    livewire(OutlookReportPreview::class)
        ->call('load', [$notMine->id])
        ->assertDontSee('Not mine');
});

test('a Moderate Day 1 shows the active PWO in the preview', function () {
    Carbon::setTestNow('2026-10-31 20:00:00 UTC');
    Http::fake(orpFiles(['MDT']));
    $pwo = Product::factory()->create(['code' => 'PWO', 'name' => 'SPC Public Severe Weather Outlook']);
    IngestedProduct::factory()->for($pwo)->create(['text' => 'Tornadoes are likely.', 'received_at' => '2026-10-31 14:00:00']);

    livewire(OutlookReportPreview::class)
        ->call('load', [])
        ->assertSee('Tornadoes are likely.');
});

test('an SPC outage shows a friendly message instead of the report', function () {
    Http::fake(['www.spc.noaa.gov/*' => Http::response('', 500)]);

    livewire(OutlookReportPreview::class)
        ->call('load', [])
        ->assertSee('SPC is unavailable right now')
        ->assertDontSeeText('Day 1 -');
});

test('an error while building the preview shows the friendly message and logs only its class', function () {
    Log::spy();
    $this->mock(FetchSpcOutlooks::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('secret detail'));

    livewire(OutlookReportPreview::class)
        ->call('load', [])
        ->assertSee('SPC is unavailable right now');

    Log::shouldHaveReceived('warning')->with('Outlook report preview failed', ['exception' => RuntimeException::class])->once();
});

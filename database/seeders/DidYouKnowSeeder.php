<?php

namespace Database\Seeders;

use App\Models\DidYouKnow;
use Illuminate\Database\Seeder;

/**
 * Populates the dashboard sidebar's "Did You Know?" rotating panel with a
 * mix of real NWS/meteorology facts and accurate tips about WxNotify's own
 * built features. Keyed on `body` via updateOrCreate so re-running never
 * duplicates rows, matching the established pattern in ProductCatalogSeeder.
 */
class DidYouKnowSeeder extends Seeder
{
    /**
     * @return array<int, string>
     */
    public static function facts(): array
    {
        return [
            'A Tornado Watch means conditions are favorable for tornadoes; a Tornado Warning means one has been spotted or indicated by radar — take shelter immediately.',
            'You can add up to five saved locations on a Plus plan, or unlimited on Pro — perfect for keeping an eye on family in another state.',
            'Setting up Quiet Hours on an alert rule doesn\'t block life-safety alerts for Nationwide, WFO, or Forecast Discussion rules — those always deliver, day or night.',
            'The National Weather Service issues a Flash Flood Warning when flooding is imminent or already happening, versus a Flash Flood Watch when conditions are just favorable for it.',
            'WxNotify can match alerts to your exact county or forecast zone, or to a custom polygon — useful if you live near a county line and only want warnings that actually reach you.',
            'A Severe Thunderstorm Warning is issued for winds of 58 mph or greater, or hail one inch in diameter or larger — roughly the size of a quarter.',
            'You can connect more than one notification channel — email, SMS, Discord, and more — so a single alert can reach you everywhere at once.',
            'Forecast Discussions are written by real meteorologists at your local NWS office, explaining the reasoning behind the forecast — not just the numbers.',
            'The Storm Prediction Center\'s Convective Outlook ranks severe weather risk from Marginal (1) to High (5) — a "High" risk day is rare, expect widespread significant severe weather.',
            'Lightning can strike more than 10 miles from the parent thunderstorm, even when the sky overhead looks clear — "when thunder roars, go indoors."',
            'You can pick exactly which WFO (Weather Forecast Office) an alert rule watches — handy if you commute across office boundaries and want discussions from a specific office.',
            'A Winter Storm Warning means significant snow, ice, or a mix is expected soon; a Winter Weather Advisory means conditions will be more of a nuisance than dangerous.',
        ];
    }

    public function run(): void
    {
        foreach (self::facts() as $index => $body) {
            DidYouKnow::query()->updateOrCreate(
                ['body' => $body],
                ['is_active' => true, 'sort_order' => $index],
            );
        }
    }
}

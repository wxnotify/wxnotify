<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChannelTypeSeeder extends Seeder
{
    /**
     * The shipped channel-type catalog; shared by the create_channel_types
     * migration and the Reset to Factory Defaults catalog reset.
     *
     * @var list<array{slug: string, min_tier: string, sort_order: int, enabled: bool}>
     */
    public const SHIPPED = [
        ['slug' => 'email', 'min_tier' => 'free', 'sort_order' => 1, 'enabled' => true],
        ['slug' => 'ntfy', 'min_tier' => 'free', 'sort_order' => 2, 'enabled' => true],
        ['slug' => 'discord', 'min_tier' => 'free', 'sort_order' => 3, 'enabled' => true],
        ['slug' => 'slack', 'min_tier' => 'free', 'sort_order' => 4, 'enabled' => true],
        ['slug' => 'telegram', 'min_tier' => 'free', 'sort_order' => 5, 'enabled' => true],
        ['slug' => 'pushover', 'min_tier' => 'free', 'sort_order' => 6, 'enabled' => true],
        ['slug' => 'msteams', 'min_tier' => 'free', 'sort_order' => 7, 'enabled' => true],
        ['slug' => 'webhook', 'min_tier' => 'free', 'sort_order' => 8, 'enabled' => true],
        ['slug' => 'webpush', 'min_tier' => 'free', 'sort_order' => 9, 'enabled' => true],
        ['slug' => 'mobile_push', 'min_tier' => 'free', 'sort_order' => 10, 'enabled' => true],
        ['slug' => 'twilio_sms', 'min_tier' => 'plus', 'sort_order' => 11, 'enabled' => false],
        ['slug' => 'bulkvs_sms', 'min_tier' => 'plus', 'sort_order' => 12, 'enabled' => false],
        ['slug' => 'httpsms', 'min_tier' => 'plus', 'sort_order' => 13, 'enabled' => false],
        ['slug' => 'twilio_voice', 'min_tier' => 'plus', 'sort_order' => 14, 'enabled' => false],
        ['slug' => 'whatsapp', 'min_tier' => 'plus', 'sort_order' => 15, 'enabled' => false],
        ['slug' => 'google_chat', 'min_tier' => 'plus', 'sort_order' => 16, 'enabled' => true],
        ['slug' => 'mattermost', 'min_tier' => 'plus', 'sort_order' => 17, 'enabled' => true],
        ['slug' => 'rocketchat', 'min_tier' => 'plus', 'sort_order' => 18, 'enabled' => true],
        ['slug' => 'matrix', 'min_tier' => 'plus', 'sort_order' => 19, 'enabled' => true],
        ['slug' => 'pagerduty', 'min_tier' => 'plus', 'sort_order' => 20, 'enabled' => true],
        ['slug' => 'bluesky', 'min_tier' => 'plus', 'sort_order' => 21, 'enabled' => true],
    ];

    public function run(): void
    {
        foreach (self::SHIPPED as $row) {
            $exists = DB::table('channel_types')->where('slug', $row['slug'])->exists();

            if ($exists) {
                DB::table('channel_types')->where('slug', $row['slug'])->update($row + ['updated_at' => now()]);
            } else {
                DB::table('channel_types')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}

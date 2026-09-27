<?php

use App\Models\NotificationChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function emailMigrationUnderTest(): Migration
{
    return require database_path('migrations/2026_09_28_100001_move_email_channels_to_mail_transport.php');
}

test('existing mailto email channels get their address in config and lose the apprise url', function () {
    $verifiedAt = now()->subDay()->startOfSecond();
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'email',
        'apprise_url' => 'mailto://mailpit:1025/?to=subscriber%40example.com&from=alerts%40wxnotify.test&mode=insecure',
        'verified_at' => $verifiedAt,
    ]);

    emailMigrationUnderTest()->up();

    $fresh = $channel->fresh();
    expect($fresh->config)->toBe(['to' => 'subscriber@example.com'])
        ->and($fresh->apprise_url)->toBeNull()
        ->and($fresh->verified_at->equalTo($verifiedAt))->toBeTrue();
});

test('other channel types and unparsable email rows are left untouched', function () {
    $discord = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);
    $unparsable = NotificationChannel::factory()->create(['channel_type' => 'email', 'apprise_url' => 'mailto://mailpit:1025/?mode=insecure']);

    emailMigrationUnderTest()->up();

    expect($discord->fresh()->apprise_url)->toBe('discord://1/abc/')
        ->and($discord->fresh()->config)->toBeNull()
        ->and($unparsable->fresh()->apprise_url)->toBe('mailto://mailpit:1025/?mode=insecure')
        ->and($unparsable->fresh()->config)->toBeNull();
});

test('the migration leaves an already migrated channel unchanged', function () {
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    emailMigrationUnderTest()->up();

    expect($channel->fresh()->config)->toBe(['to' => 'subscriber@example.com'])
        ->and($channel->fresh()->apprise_url)->toBeNull();
});

test('rolling back rebuilds a working mailto url from the address and the mail config', function () {
    config([
        'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025,
        'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.encryption' => null, 'mail.from.address' => 'alerts@wxnotify.test',
    ]);
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();
    $discord = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    emailMigrationUnderTest()->down();

    expect($channel->fresh()->apprise_url)->toBe('mailto://mailpit:1025/?to=subscriber%40example.com&from=alerts%40wxnotify.test&mode=insecure')
        ->and($channel->fresh()->config)->toBeNull()
        ->and($discord->fresh()->apprise_url)->toBe('discord://1/abc/');
});

test('a plus address survives the migration and the rollback', function () {
    config([
        'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025,
        'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.encryption' => null, 'mail.from.address' => 'alerts@wxnotify.test',
    ]);
    $url = 'mailto://mailpit:1025/?to=a%2Bb%40example.com&from=alerts%40wxnotify.test&mode=insecure';
    $channel = NotificationChannel::factory()->create(['channel_type' => 'email', 'apprise_url' => $url]);

    emailMigrationUnderTest()->up();
    expect($channel->fresh()->config)->toBe(['to' => 'a+b@example.com']);

    emailMigrationUnderTest()->down();
    expect($channel->fresh()->apprise_url)->toBe($url);
});

test('an undecryptable row is skipped while other rows still migrate', function () {
    $garbage = NotificationChannel::factory()->create(['channel_type' => 'email', 'apprise_url' => 'mailto://x/?to=a%40example.com']);
    DB::table('notification_channels')->where('id', $garbage->id)->update(['apprise_url' => 'not-a-valid-ciphertext']);
    $good = NotificationChannel::factory()->create(['channel_type' => 'email', 'apprise_url' => 'mailto://mailpit:1025/?to=ok%40example.com&mode=insecure']);

    emailMigrationUnderTest()->up();

    expect($good->fresh()->config)->toBe(['to' => 'ok@example.com'])
        ->and(DB::table('notification_channels')->where('id', $garbage->id)->value('apprise_url'))->toBe('not-a-valid-ciphertext');
});

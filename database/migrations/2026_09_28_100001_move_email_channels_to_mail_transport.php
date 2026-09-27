<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $skipped = [];

        DB::table('notification_channels')
            ->where('channel_type', 'email')
            ->whereNotNull('apprise_url')
            ->whereNull('config')
            ->orderBy('id')
            ->each(function (object $row) use (&$skipped): void {
                $url = Crypt::decryptString($row->apprise_url);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $to = $query['to'] ?? null;

                if (! is_string($to) || $to === '') {
                    $skipped[] = $row->id;

                    return;
                }

                DB::table('notification_channels')->where('id', $row->id)->update([
                    'config' => Crypt::encryptString(json_encode(['to' => $to])),
                    'apprise_url' => null,
                ]);
            });

        if ($skipped !== []) {
            Log::warning('Email channels left unchanged because no recipient address could be read from their stored URL', ['notification_channel_ids' => $skipped]);
        }
    }

    public function down(): void
    {
        $host = config('mail.mailers.smtp.host', 'localhost');
        $port = config('mail.mailers.smtp.port', 587);
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');
        $from = config('mail.from.address');
        $mode = match (config('mail.mailers.smtp.encryption')) {
            'tls', 'starttls' => 'starttls',
            'ssl' => 'ssl',
            default => 'insecure',
        };
        $auth = $username ? rawurlencode($username).':'.rawurlencode((string) $password).'@' : '';

        DB::table('notification_channels')
            ->where('channel_type', 'email')
            ->whereNull('apprise_url')
            ->whereNotNull('config')
            ->orderBy('id')
            ->each(function (object $row) use ($host, $port, $auth, $from, $mode): void {
                $config = json_decode(Crypt::decryptString($row->config), true);
                $to = is_array($config) ? ($config['to'] ?? null) : null;

                if (! is_string($to) || $to === '') {
                    return;
                }

                $url = "mailto://{$auth}{$host}:{$port}/?to=".rawurlencode($to).'&from='.rawurlencode((string) $from)."&mode={$mode}";

                DB::table('notification_channels')->where('id', $row->id)->update([
                    'apprise_url' => Crypt::encryptString($url),
                    'config' => null,
                ]);
            });
    }
};

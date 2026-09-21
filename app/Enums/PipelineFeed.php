<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum PipelineFeed: string
{
    case Nwws = 'nwws';
    case Emwin = 'emwin';
    case EmwinHttp = 'emwin_http';

    public function label(): string
    {
        return match ($this) {
            self::Nwws => 'NWWS',
            self::Emwin => 'EMWIN Byteblaster',
            self::EmwinHttp => 'EMWIN HTTP backup',
        };
    }

    public function container(): string
    {
        return match ($this) {
            self::Nwws => 'wxnotify-nwws',
            self::Emwin => 'wxnotify-emwin-byteblaster',
            self::EmwinHttp => 'wxnotify-emwin-http',
        };
    }

    public function isPrimary(): bool
    {
        return $this !== self::EmwinHttp;
    }

    public function role(): string
    {
        return match ($this) {
            self::Nwws => 'Primary · NWWS-OI XMPP feed',
            self::Emwin => 'Primary · satellite EMWIN via Byteblaster',
            self::EmwinHttp => 'Backup · NOAA EMWIN HTTP polling',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Nwws => Heroicon::OutlinedSignal,
            self::Emwin => Heroicon::OutlinedRss,
            self::EmwinHttp => Heroicon::OutlinedCloudArrowDown,
        };
    }

    public function staleMinutes(): int
    {
        return (int) config("wxnotify.pipeline.stale_minutes.{$this->value}");
    }
}

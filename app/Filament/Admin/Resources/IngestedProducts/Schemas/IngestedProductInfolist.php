<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IngestedProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('cccc')->label('Office')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('ttaaii')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('awipsid')->label('AWIPS ID')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('wmo_ddhhmm')->label('Issued (DDHHMM)')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('text')
                    ->label('Product text')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
            ]);
    }
}

<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Schemas;

use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\IngestedProduct;
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
                TextEntry::make('ttaaii')->label('TTAAII')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('awipsid')->label('AWIPS ID')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('wmo_ddhhmm')->label('Issued (DDHHMM)')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('scenario')
                    ->label('Test scenario')
                    ->state(fn (IngestedProduct $record): ?string => $record->is_test && TestProductScenarioResource::canAccess()
                        ? $record->scenario?->name
                        : null)
                    ->url(fn (IngestedProduct $record): ?string => $record->is_test && $record->test_product_scenario_id !== null && TestProductScenarioResource::canAccess()
                        ? TestProductScenarioResource::getUrl('edit', ['record' => $record->test_product_scenario_id])
                        : null)
                    ->visible(fn (IngestedProduct $record): bool => $record->is_test && $record->test_product_scenario_id !== null && TestProductScenarioResource::canAccess()),
                TextEntry::make('text')
                    ->label('Product text')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
            ]);
    }
}

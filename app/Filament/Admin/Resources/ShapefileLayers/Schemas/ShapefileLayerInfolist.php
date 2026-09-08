<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Schemas;

use App\Models\ShapefileLayer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShapefileLayerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pending review')
                    ->visible(fn (ShapefileLayer $record): bool => $record->pendingVersion !== null)
                    ->schema([
                        TextEntry::make('pendingVersion.filename')->label('File'),
                        TextEntry::make('pendingVersion.effective_at')->label('Effective date')->date(),
                        TextEntry::make('pendingVersion.diff_summary.added')->label('Added'),
                        TextEntry::make('pendingVersion.diff_summary.removed')->label('Removed'),
                        TextEntry::make('pendingVersion.diff_summary.changed')->label('Changed'),
                        TextEntry::make('pendingVersion.diff_summary.override_conflicts')
                            ->label('Overrides in conflict with NOAA\'s new data')
                            ->formatStateUsing(fn (?array $state): string => $state === null || $state === [] ? 'None' : implode(', ', $state))
                            ->color(fn (?array $state): string => $state === null || $state === [] ? 'gray' : 'danger'),
                    ]),
                Section::make('Active version')
                    ->schema([
                        TextEntry::make('activeVersion.filename')->label('File')->placeholder('Never activated'),
                        TextEntry::make('activeVersion.effective_at')->label('Effective date')->date()->placeholder('—'),
                        TextEntry::make('activeVersion.activated_at')->label('Activated')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }
}

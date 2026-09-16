<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates;

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\CreateAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\EditAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\ListAlertTemplates;
use App\Filament\Dashboard\Resources\AlertTemplates\Schemas\AlertTemplateForm;
use App\Filament\Dashboard\Resources\AlertTemplates\Tables\AlertTemplatesTable;
use App\Models\AlertTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AlertTemplateResource extends Resource
{
    protected static ?string $model = AlertTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Alerting';

    protected static ?string $navigationLabel = 'Alert Templates';

    protected static ?string $modelLabel = 'alert template';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return AlertTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertTemplates::route('/'),
            'create' => CreateAlertTemplate::route('/create'),
            'edit' => EditAlertTemplate::route('/{record}/edit'),
        ];
    }
}

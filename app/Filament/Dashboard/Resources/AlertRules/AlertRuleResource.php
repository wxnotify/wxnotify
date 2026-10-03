<?php

namespace App\Filament\Dashboard\Resources\AlertRules;

use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\AlertRuleForm;
use App\Filament\Dashboard\Resources\AlertRules\Tables\AlertRulesTable;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\NeedsAttentionWidget;
use App\Models\AlertRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AlertRuleResource extends Resource
{
    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Alerting';

    protected static ?string $navigationLabel = 'My Alerts';

    protected static ?string $modelLabel = 'alert';

    protected static ?int $navigationSort = -1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return AlertRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getWidgets(): array
    {
        return [
            AlertStatsWidget::class,
            NeedsAttentionWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertRules::route('/'),
            'create' => CreateAlertRule::route('/create'),
            'edit' => EditAlertRule::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }
}

<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules;

use App\Filament\Admin\Resources\ActivityAlertRules\Pages\CreateActivityAlertRule;
use App\Filament\Admin\Resources\ActivityAlertRules\Pages\EditActivityAlertRule;
use App\Filament\Admin\Resources\ActivityAlertRules\Pages\ListActivityAlertRules;
use App\Filament\Admin\Resources\ActivityAlertRules\Schemas\ActivityAlertRuleForm;
use App\Filament\Admin\Resources\ActivityAlertRules\Tables\ActivityAlertRulesTable;
use App\Models\ActivityAlertRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ActivityAlertRuleResource extends Resource
{
    protected static ?string $model = ActivityAlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Trust & Audit';

    protected static ?string $navigationLabel = 'Activity Alert Rules';

    protected static ?string $modelLabel = 'activity alert rule';

    public static function form(Schema $schema): Schema
    {
        return ActivityAlertRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityAlertRulesTable::configure($table);
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
            'index' => ListActivityAlertRules::route('/'),
            'create' => CreateActivityAlertRule::route('/create'),
            'edit' => EditActivityAlertRule::route('/{record}/edit'),
        ];
    }
}

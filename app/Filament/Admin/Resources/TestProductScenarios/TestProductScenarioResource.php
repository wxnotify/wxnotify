<?php

namespace App\Filament\Admin\Resources\TestProductScenarios;

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\EditTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\Tables\TestProductScenariosTable;
use App\Models\TestProductScenario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TestProductScenarioResource extends Resource
{
    protected static ?string $model = TestProductScenario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Test Product Generator';

    protected static ?string $modelLabel = 'test product scenario';

    /**
     * Hidden entirely in production -- not merely disabled -- so there is
     * no code path by which a generated product can enter a live
     * environment. See the design doc's Safety Model section.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ! app()->environment('production');
    }

    public static function canAccess(): bool
    {
        return ! app()->environment('production');
    }

    public static function form(Schema $schema): Schema
    {
        return TestProductScenarioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TestProductScenariosTable::configure($table);
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
            'index' => ListTestProductScenarios::route('/'),
            'create' => CreateTestProductScenario::route('/create'),
            'edit' => EditTestProductScenario::route('/{record}/edit'),
        ];
    }
}

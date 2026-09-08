<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Actions\Shapefiles\ApplyRecordOverrides;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileRecordOverride;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShapefileLayerRecords extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ShapefileLayerResource::class;

    protected string $view = 'filament.admin.pages.shapefile-layer-records';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function table(Table $table): Table
    {
        $definition = ShapefileLayerDefinitions::forKey($this->record->key);

        return $table
            ->query($definition->modelClass::query())
            ->columns($this->columnsFor($definition))
            ->recordActions([
                Action::make('override')
                    ->label('Override')
                    ->schema([
                        Select::make('field')
                            ->label('Field')
                            ->options($this->overridableFields($definition))
                            ->required(),
                        TextInput::make('value')
                            ->label('New value')
                            ->required(),
                    ])
                    ->action(function (array $data, Model $record) use ($definition): void {
                        $this->applyOverride($definition, $record, $data['field'], $data['value']);
                    }),
            ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    private function columnsFor(ShapefileLayerDefinition $definition): array
    {
        return collect($definition->attributeMap)
            ->values()
            ->unique()
            ->map(fn (string $column): TextColumn => TextColumn::make($column)
                ->label(ucfirst(str_replace('_', ' ', $column)))
                ->searchable())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function overridableFields(ShapefileLayerDefinition $definition): array
    {
        $excluded = [...$definition->naturalKeyColumns, 'lon', 'lat'];

        return collect($definition->attributeMap)
            ->values()
            ->unique()
            ->reject(fn (string $column): bool => in_array($column, $excluded, true))
            ->mapWithKeys(fn (string $column): array => [$column => ucfirst(str_replace('_', ' ', $column))])
            ->all();
    }

    private function applyOverride(ShapefileLayerDefinition $definition, Model $record, string $field, string $value): void
    {
        $recordKey = $definition->serializeNaturalKey(
            collect($definition->naturalKeyColumns)
                ->mapWithKeys(fn (string $column): array => [$column => $record->getAttribute($column)])
                ->all(),
        );

        ShapefileRecordOverride::updateOrCreate(
            ['shapefile_layer_id' => $this->record->id, 'record_key' => $recordKey, 'field' => $field],
            ['value' => $value, 'created_by' => auth()->id()],
        );

        app(ApplyRecordOverrides::class)($this->record, $definition);

        Notification::make()->title('Override applied')->success()->send();
    }
}

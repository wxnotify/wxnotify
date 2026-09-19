<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Tables;

use App\Actions\Testing\GenerateTestProduct;
use App\Actions\Testing\NotifyGenerationOutcome;
use App\Models\TestProductScenario;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TestProductScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('Untitled scenario'),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('ingested_products_count')
                    ->label('Times sent')
                    ->counts('ingestedProducts'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('send_again')
                    ->label('Send again')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (TestProductScenario $record): void {
                        // Render-only: the stored scenario stays exactly as saved, but a resend
                        // should always look freshly issued, matching EditTestProductScenario's
                        // mutateFormDataBeforeFill() reset for the wizard-based path.
                        $record->input = [...$record->input, 'issued_at' => now()->toIso8601String()];

                        app(NotifyGenerationOutcome::class)(app(GenerateTestProduct::class)($record));
                    })
                    ->hidden(fn (TestProductScenario $record): bool => $record->product_id === null),
                EditAction::make()
                    ->label('Edit & resend'),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (TestProductScenario $record): void {
                        TestProductScenario::create([
                            'name' => $record->name !== null ? "{$record->name} (copy)" : null,
                            'product_id' => $record->product_id,
                            'input' => $record->input,
                        ]);

                        Notification::make()
                            ->title('Scenario duplicated')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }
}

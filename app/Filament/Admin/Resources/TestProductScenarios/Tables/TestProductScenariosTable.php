<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Tables;

use App\Actions\Testing\GenerateTestProduct;
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
                        $result = app(GenerateTestProduct::class)($record);

                        if ($result === null) {
                            Notification::make()
                                ->title('Failed to generate the test product')
                                ->body('The synthetic text could not be parsed. Check the scenario\'s fields and try again.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Test product generated')
                            ->success()
                            ->send();
                    }),
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

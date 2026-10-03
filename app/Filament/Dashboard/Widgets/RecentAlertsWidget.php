<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\DeliveryLog;
use App\Support\Units;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * A glance at what this subscriber has actually been sent recently, not a
 * full history (that's a future dedicated page, not this widget) --
 * DeliveryLog::user_id scoping plus a fixed, unpaginated 8-row limit rather
 * than a real paginated/searchable table.
 *
 * subject falls back to the rule's own displayName() for any row logged
 * before this column existed (both stay null together on those older rows,
 * so the fallback still resolves to something rather than a blank cell).
 */
class RecentAlertsWidget extends TableWidget
{
    protected static ?int $sort = -1;

    protected int | string | array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DeliveryLog::query()
                    ->where('user_id', auth()->id())
                    ->latest('dispatched_at')
                    ->limit(8)
            )
            ->heading('Recent Alerts')
            ->paginated(false)
            ->columns([
                TextColumn::make('subject')
                    ->label('Alert')
                    ->getStateUsing(fn (DeliveryLog $record): string => $record->subject ?? $record->alertRule?->displayName(Units::for(auth()->user())->system) ?? 'Alert'),
                TextColumn::make('channel_type')
                    ->label('Channel')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failure' => 'danger',
                        'suppressed', 'skipped' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('dispatched_at')
                    ->label('When')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('viewProduct')
                    ->label('View')
                    ->link()
                    ->visible(fn (DeliveryLog $record): bool => $record->ingestedProduct?->viewUrl() !== null)
                    ->url(fn (DeliveryLog $record): ?string => $record->ingestedProduct?->viewUrl())
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No alerts yet')
            ->emptyStateDescription('Alerts you receive will show up here once one of your rules matches.');
    }
}

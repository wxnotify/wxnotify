<?php

namespace App\Notifications;

use App\Models\ShapefileLayerVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

class ShapefileLayerUpdateAvailableNotification extends Notification
{
    public function __construct(
        public ShapefileLayerVersion $version,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Built via Filament's own Notification (title/body/icon shape, tagged
     * format => filament) rather than a plain array, so this also renders
     * in the admin panel's database-notifications bell — not just the
     * System Notifications browse table every notification lands in.
     *
     * The action URL is built with the plain route() helper rather than
     * ShapefileLayerResource::getUrl(): this notification is dispatched
     * from ImportShapefileVersion, which shapefile:scan's daily scheduled
     * run invokes with no HTTP request and so no Filament "current panel"
     * context — getUrl() throws RouteNotFoundException there (confirmed by
     * reproducing it directly), where route() with the panel's fully
     * qualified route name needs no such context.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $diff = $this->version->diff_summary ?? ['added' => 0, 'removed' => 0, 'changed' => 0];
        $layer = $this->version->shapefileLayer;

        return FilamentNotification::make()
            ->title("{$layer->name} update available")
            ->body("{$diff['added']} added, {$diff['removed']} removed, {$diff['changed']} changed — review and activate.")
            ->icon(Heroicon::OutlinedMap)
            ->warning()
            ->actions([
                Action::make('view')
                    ->label('Review')
                    ->url(route('filament.admin.resources.shapefile-layers.view', ['record' => $layer]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}

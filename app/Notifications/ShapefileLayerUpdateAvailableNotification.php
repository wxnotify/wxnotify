<?php

namespace App\Notifications;

use App\Models\ShapefileLayerVersion;
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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $diff = $this->version->diff_summary ?? ['added' => 0, 'removed' => 0, 'changed' => 0];
        $layer = $this->version->shapefileLayer;

        return [
            'message' => "{$layer->name} has a pending update: {$diff['added']} added, {$diff['removed']} removed, {$diff['changed']} changed — review and activate.",
            'shapefile_layer_id' => $layer->id,
            'shapefile_layer_version_id' => $this->version->id,
        ];
    }
}

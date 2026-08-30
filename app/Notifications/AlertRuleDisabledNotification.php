<?php

namespace App\Notifications;

use App\Models\AlertRule;
use App\Models\Product;
use Illuminate\Notifications\Notification;

class AlertRuleDisabledNotification extends Notification
{
    public function __construct(
        public Product $product,
        public AlertRule $alertRule,
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
        return [
            'message' => "Your alert \"{$this->alertRule->displayName()}\" was disabled because {$this->product->name} was retired.",
            'alert_rule_id' => $this->alertRule->id,
            'product_id' => $this->product->id,
        ];
    }
}

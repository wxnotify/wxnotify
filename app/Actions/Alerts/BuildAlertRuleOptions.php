<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ProductCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Models\AlertTemplate;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Units;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the alert rule form needs in one read-only payload: the choices the dashboard form
 * builds from its enums, product catalog, settings and the subscriber's own channels, templates
 * and locations. Channel entries never carry a destination or URL.
 */
class BuildAlertRuleOptions
{
    /** The product families a Weather Alerts rule can choose from (the dashboard form's own list). */
    public const WEATHER_FAMILIES = ['warning', 'watch', 'advisory', 'statement', 'informational', 'administrative', 'aviation', 'forecast_discussion'];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $units = Units::for($user);
        $limits = app(TierThresholdSettings::class);
        $min = $units->minDistanceDisplay($limits->lightning_min_radius_miles);
        $max = $units->maxDistanceDisplay(50);
        $registry = app(ChannelTypeRegistry::class);

        return [
            'categories' => collect(AlertCategory::cases())->map(fn (AlertCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
            ])->all(),
            'products' => [
                'weather_alerts' => $this->groups(Product::query()->whereIn('family', self::WEATHER_FAMILIES)->where('status', 'active')),
                'spc_wpc' => $this->groups(Product::query()->where('family', 'spc_wpc')->where('status', 'active')),
            ],
            'matching_modes' => collect(MatchingMode::cases())->map(fn (MatchingMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->helperText(),
            ])->all(),
            'thresholds' => [
                'variables' => collect(ThresholdVariable::cases())->map(fn (ThresholdVariable $variable): array => [
                    'value' => $variable->value,
                    'label' => $variable->label(),
                    'unit' => $variable->unit($units->system),
                    'decimals' => $variable->displayDecimals($units->system),
                ])->all(),
                'directions' => collect(ThresholdDirection::cases())->map(fn (ThresholdDirection $d): array => ['value' => $d->value, 'label' => $d->label()])->all(),
                'timeframes' => collect(ThresholdTimeframe::cases())->map(fn (ThresholdTimeframe $t): array => ['value' => $t->value, 'label' => $t->label()])->all(),
            ],
            'lightning' => [
                'unit' => $units->distanceUnit(),
                'min' => $min,
                'max' => $max,
                'default' => min($max, max($min, (int) round($units->distanceValue(15)))),
            ],
            'units' => ['system' => $units->system->value],
            'tier' => ['can_customize_messages' => $user->tier() !== Tier::Free],
            'message_body_styles' => collect(MessageBodyStyle::cases())->map(fn (MessageBodyStyle $s): array => ['value' => $s->value, 'label' => $s->label()])->all(),
            'channels' => $user->notificationChannels()->orderBy('label')->orderBy('id')->get()->map(fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'label' => $channel->label,
                'type' => $channel->channel_type,
                'type_label' => $registry->find($channel->channel_type)?->label() ?? $channel->channel_type,
                'verified' => $channel->isVerified(),
            ])->all(),
            'templates' => [
                'weather_alerts' => $this->templates($user, AlertCategory::WeatherAlerts),
                'spc_wpc' => $this->templates($user, AlertCategory::SpcWpc),
            ],
            'locations' => $user->locations()->orderBy('label')->orderBy('id')->get(['id', 'label'])
                ->map(fn ($location): array => ['id' => $location->id, 'label' => $location->label])->all(),
            'can_create_location' => $user->canCreateLocation(),
        ];
    }

    /**
     * One group per product category present, in the dashboard's section order.
     *
     * @param  Builder<Product>  $query
     * @return list<array{category: string, label: string, products: list<array<string, mixed>>}>
     */
    private function groups(Builder $query): array
    {
        $byCategory = $query->orderBy('sort_order')->get(['id', 'name', 'description', 'category', 'risk_level_options'])
            ->groupBy(fn (Product $product): ?string => $product->category?->value);

        return collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $byCategory->has($category->value))
            ->map(fn (ProductCategory $category): array => [
                'category' => $category->value,
                'label' => $category->label(),
                'products' => $byCategory->get($category->value)->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'risk_levels' => $product->risk_level_options ?? [],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function templates(User $user, AlertCategory $category): array
    {
        return AlertTemplate::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AlertTemplate $template): array => ['id' => $template->id, 'name' => $template->name])
            ->all();
    }
}

<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\AlertCategory;
use App\Models\AlertRule;
use Filament\Widgets\ChartWidget;

class AlertRulesByCategoryChart extends ChartWidget
{
    protected ?string $heading = 'Alert Rules by Category';

    protected function getData(): array
    {
        $counts = AlertRule::query()
            ->selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category');

        $categories = collect(AlertCategory::cases());

        return [
            'datasets' => [
                [
                    'label' => 'Alert rules',
                    'data' => $categories->map(fn (AlertCategory $category) => $counts[$category->value] ?? 0)->all(),
                ],
            ],
            'labels' => $categories->map(fn (AlertCategory $category) => $category->label())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}

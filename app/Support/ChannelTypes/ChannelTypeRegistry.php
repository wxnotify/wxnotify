<?php

namespace App\Support\ChannelTypes;

use App\Models\ChannelType as ChannelTypeModel;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Support\Collection;

class ChannelTypeRegistry
{
    /**
     * @var array<string, class-string<ChannelType>>
     */
    protected static array $classes = [
        'email' => EmailChannelType::class,
        'ntfy' => NtfyChannelType::class,
        'discord' => DiscordChannelType::class,
        'slack' => SlackChannelType::class,
        'telegram' => TelegramChannelType::class,
        'pushover' => PushoverChannelType::class,
        'msteams' => TeamsChannelType::class,
    ];

    public function find(string $slug): ?ChannelType
    {
        $class = static::$classes[$slug] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * All channel types the picker should render, in configured sort order —
     * enabled ones with their PHP class present, joined with DB-editable
     * enabled/min_tier/sort_order metadata.
     *
     * @return Collection<int, array{model: ChannelTypeModel, type: ChannelType}>
     */
    public function forPicker(): Collection
    {
        return ChannelTypeModel::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ChannelTypeModel $model) => [
                'model' => $model,
                'type' => $this->find($model->slug),
            ])
            ->filter(fn (array $row) => $row['type'] !== null && $row['type']->isAvailable())
            ->values();
    }
}

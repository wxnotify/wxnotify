<?php

namespace App\Support\ChannelTypes;

/**
 * One field of a channel type's setup, described without Filament so the mobile API can publish it
 * and validate with it. `rules` are Laravel validation rules and are never published.
 */
final class ChannelSetupField
{
    public const TEXT = 'text';

    public const EMAIL = 'email';

    public const URL = 'url';

    /** A value that acts as a password (a bot token, a webhook URL): masked while typed. */
    public const SECRET = 'secret';

    /**
     * @param  list<mixed>  $rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $input = self::TEXT,
        public readonly bool $required = true,
        public readonly ?string $help = null,
        public readonly ?string $placeholder = null,
        public readonly ?string $default = null,
        public readonly array $rules = [],
    ) {}

    /**
     * @return array{key: string, label: string, input: string, required: bool, help: ?string, placeholder: ?string, default: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'input' => $this->input,
            'required' => $this->required,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'default' => $this->default,
        ];
    }
}

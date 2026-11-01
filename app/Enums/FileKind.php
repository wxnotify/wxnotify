<?php

namespace App\Enums;

enum FileKind: string
{
    case Text = 'text';
    case Image = 'image';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text products',
            self::Image => 'Images',
            self::Other => 'Other',
        };
    }

    public static function fromFileName(string $name): self
    {
        $lower = strtolower($name);

        if (preg_match('/\.txt(\.\d+)?$/', $lower) === 1) {
            return self::Text;
        }

        return match (pathinfo($lower, PATHINFO_EXTENSION)) {
            'gif', 'jpg', 'jpeg', 'png' => self::Image,
            default => self::Other,
        };
    }
}

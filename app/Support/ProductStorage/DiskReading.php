<?php

namespace App\Support\ProductStorage;

final readonly class DiskReading
{
    public function __construct(
        public string $label,
        public string $path,
        public ?int $usedBytes,
        public ?int $totalBytes,
    ) {}

    public function isAvailable(): bool
    {
        return $this->usedBytes !== null && $this->totalBytes !== null && $this->totalBytes > 0;
    }

    public function percent(): ?float
    {
        if (! $this->isAvailable()) {
            return null;
        }

        return round($this->usedBytes / $this->totalBytes * 100, 1);
    }

    /**
     * @return 'normal'|'warning'|'critical'|'unavailable'
     */
    public function band(): string
    {
        $percent = $this->percent();

        return match (true) {
            $percent === null => 'unavailable',
            $percent >= 90 => 'critical',
            $percent >= 80 => 'warning',
            default => 'normal',
        };
    }
}

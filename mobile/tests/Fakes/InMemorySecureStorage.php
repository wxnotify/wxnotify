<?php

namespace Tests\Fakes;

use Native\Mobile\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;

class InMemorySecureStorage extends SecureStorage
{
    /** @var array<string, string> */
    public array $items = [];

    /** @var array<string, SecureStorageAccessibility|null> */
    public array $accessibility = [];

    public bool $failWrites = false;

    public function set(string $key, ?string $value, ?SecureStorageAccessibility $accessibility = null): bool
    {
        if ($this->failWrites) {
            return false;
        }

        if ($value === null) {
            unset($this->items[$key], $this->accessibility[$key]);

            return true;
        }

        $this->items[$key] = $value;
        $this->accessibility[$key] = $accessibility;

        return true;
    }

    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key], $this->accessibility[$key]);

        return true;
    }

    public function accessibilityOf(string $key): ?SecureStorageAccessibility
    {
        return $this->accessibility[$key] ?? null;
    }
}

<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use Throwable;

/**
 * Activates the pending version of every layer whose import finished. A layer whose import did not
 * finish is skipped (activating it would swap an empty or stale table live), and one layer failing
 * does not stop the rest: each activation is its own transaction.
 */
class ActivateAllShapefileVersions
{
    public function __construct(private readonly ActivateShapefileVersion $activate) {}

    /**
     * @return array{activated: int, skipped: int, failed: int}
     */
    public function __invoke(): array
    {
        $result = ['activated' => 0, 'skipped' => 0, 'failed' => 0];

        ShapefileLayer::query()->with('pendingVersion')->orderBy('name')->get()
            ->each(function (ShapefileLayer $layer) use (&$result): void {
                $pending = $layer->pendingVersion;

                if ($pending === null) {
                    return;
                }

                if (! $pending->isImported()) {
                    $result['skipped']++;

                    return;
                }

                try {
                    ($this->activate)($pending);
                    $result['activated']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $result['failed']++;
                }
            });

        return $result;
    }

    public static function hasImportedPendingVersion(): bool
    {
        return ShapefileLayer::query()->with('pendingVersion')->get()
            ->contains(fn (ShapefileLayer $layer): bool => $layer->pendingVersion?->isImported() === true);
    }
}

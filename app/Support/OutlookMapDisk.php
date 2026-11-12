<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The one place the Outlook report map images' disk is chosen (OUTLOOK_MAPS_DISK), so a
 * multi-server deployment can point the delivery and API servers at a shared S3-compatible disk.
 */
class OutlookMapDisk
{
    public static function disk(): Filesystem
    {
        $name = (string) config('filesystems.outlook_maps_disk');

        try {
            return Storage::disk($name);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("OUTLOOK_MAPS_DISK is set to [{$name}], which is not a configured filesystem disk.", previous: $e);
        }
    }
}

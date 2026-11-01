<?php

namespace App\Support\ProductStorage;

class Bytes
{
    /**
     * Binary units, one decimal ("1.5 MB"); plain bytes carry no decimal ("512 B").
     */
    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return max(0, $bytes).' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, 1).' '.$units[$unit];
    }
}

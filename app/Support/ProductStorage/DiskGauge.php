<?php

namespace App\Support\ProductStorage;

class DiskGauge
{
    /**
     * @return array{nwws: string, emwin: string}
     */
    public function paths(): array
    {
        return [
            'nwws' => (string) config('services.pipeline.products_path'),
            'emwin' => (string) config('services.pipeline.emwin_products_path'),
        ];
    }

    /**
     * @return list<DiskReading>
     */
    public function readings(): array
    {
        $paths = $this->paths();
        $nwws = $this->read('NWWS disk', $paths['nwws']);
        $emwin = $this->read('EMWIN disk', $paths['emwin']);

        if ($nwws->isAvailable() && $emwin->isAvailable()) {
            $nwwsDevice = $this->deviceOf($paths['nwws']);
            $emwinDevice = $this->deviceOf($paths['emwin']);

            if ($nwwsDevice !== null && $nwwsDevice === $emwinDevice) {
                return [new DiskReading('Product disk', $nwws->path, $nwws->usedBytes, $nwws->totalBytes)];
            }
        }

        return [$nwws, $emwin];
    }

    public function deviceOf(string $path): ?int
    {
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }

    private function read(string $label, string $path): DiskReading
    {
        $total = is_dir($path) ? @disk_total_space($path) : false;
        $free = $total === false ? false : @disk_free_space($path);

        if ($total === false || $free === false) {
            return new DiskReading($label, $path, null, null);
        }

        return new DiskReading($label, $path, (int) ($total - $free), (int) $total);
    }
}

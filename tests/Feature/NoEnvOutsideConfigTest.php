<?php

use Symfony\Component\Finder\Finder;

/**
 * Production containers cache configuration (ServerSideUp's Laravel automation runs
 * config:cache), and once it is cached env() returns null everywhere except config/.
 * A setting read with env() anywhere else silently stops working in production.
 */
test('no env() call exists outside config/', function () {
    $directories = array_map(base_path(...), ['app', 'bootstrap', 'routes', 'resources', 'database/migrations']);

    $files = Finder::create()->files()->in($directories)->name('*.php')->exclude('cache');

    $offenders = [];

    foreach ($files as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $index => $line) {
            if (preg_match('/(?<![\w$])(?<!->)(?<!::)env\(/', $line) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($index + 1).': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

<?php

use Illuminate\Support\Facades\Process;

// With no NWWS-OI account the container must not crash-loop (restart: unless-stopped restarts even
// after exit 0), so the entrypoint says why and idles until stopped. The credentials-present branch
// execs the Go client, which only exists in the image, so it is not run here.
test('the entrypoint idles with a notice when the NWWS credentials are empty, and stops cleanly on SIGTERM', function (array $env) {
    $process = Process::path(base_path())->timeout(20)->env(['PATH' => '/usr/bin:/bin'] + $env)->start(['sh', 'docker/nwws/entrypoint.sh']);

    $output = '';
    for ($i = 0; $i < 50 && ! str_contains($output, 'NWWS_USERNAME'); $i++) {
        usleep(100_000);
        $output .= $process->latestErrorOutput().$process->latestOutput();
    }

    expect($process->running())->toBeTrue()
        ->and($output)->toContain('NWWS_USERNAME')->toContain('idle');

    $process->signal(15);

    expect($process->wait()->exitCode())->toBe(0);
})->with([
    'both empty' => [['NWWS_USERNAME' => '', 'NWWS_PASSWORD' => '']],
    'both unset' => [['NWWS_USERNAME' => false, 'NWWS_PASSWORD' => false]],
    'password only' => [['NWWS_USERNAME' => '', 'NWWS_PASSWORD' => 'x']],
    'username only' => [['NWWS_USERNAME' => 'x', 'NWWS_PASSWORD' => '']],
]);

test('the entrypoint script is valid shell', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/nwws/entrypoint.sh'])->successful())->toBeTrue();
});

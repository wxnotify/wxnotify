<?php

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// pan-notify.sh never reads the file itself -- it only receives the absolute
// path nwws-go-client already wrote ($1, always under /products/ per its own
// NWWS_ARCHIVEDIR) and strips that literal prefix before posting, so no real
// file on disk is needed to exercise it.
function nwwsShRun(object $t, string $absolutePath, array $extraEnv = []): ProcessResult
{
    return Process::path(base_path())->env($extraEnv + [
        'PATH' => $t->bin.':/usr/bin:/bin',
        'PIPELINE_WEBHOOK_TOKEN' => 'secret-token',
    ])->run(['sh', 'docker/nwws/pan-notify.sh', $absolutePath]);
}

function nwwsShPosts(object $t): array
{
    if (! File::exists($t->dir.'/curl.log')) {
        return [];
    }

    // The fake curl writes one JSON body per call on its own line.
    return array_values(array_filter(array_map(
        fn ($line) => str_starts_with($line, '{"path"') ? json_decode($line, true)['path'] : null,
        explode("\n", File::get($t->dir.'/curl.log')),
    )));
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nwws-pan-'.uniqid();
    $this->bin = $this->dir.'/bin';
    File::makeDirectory($this->bin, recursive: true);

    // Fake curl: log every argument on its own line -- the URL and the
    // Authorization header each land as their own logged line this way.
    File::put($this->bin.'/curl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"".$this->dir."/curl.log\"\nexit 0\n");
    chmod($this->bin.'/curl', 0755);
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('the script is valid POSIX sh', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/nwws/pan-notify.sh'])->successful())->toBeTrue();
});

test('posts to the default laravel.test URL when NWWS_WEBHOOK_URL is unset', function () {
    $result = nwwsShRun($this, '/products/kbou/kbou_wfus53-torbou.191622_1.txt');

    expect($result->exitCode())->toBe(0)
        ->and(File::get($this->dir.'/curl.log'))
        ->toContain('http://laravel.test/api/pipeline/nwws-products')
        ->toContain('Authorization: Bearer secret-token');
});

test('posts to NWWS_WEBHOOK_URL when set, stripping the /products/ prefix', function () {
    $result = nwwsShRun($this, '/products/kbou/kbou_wfus53-torbou.191622_1.txt', [
        'NWWS_WEBHOOK_URL' => 'http://pipeline-api/api/pipeline/nwws-products',
    ]);

    expect($result->exitCode())->toBe(0)
        ->and(File::get($this->dir.'/curl.log'))->toContain('http://pipeline-api/api/pipeline/nwws-products')
        ->and(nwwsShPosts($this))->toBe(['kbou/kbou_wfus53-torbou.191622_1.txt']);
});

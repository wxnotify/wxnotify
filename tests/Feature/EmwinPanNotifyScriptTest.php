<?php

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function emwinShMakeZip(string $path, array $members, ?string $password = null): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($members as $name => $content) {
        $zip->addFromString($name, $content);
        if ($password !== null) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        }
    }
    $zip->close();
}

function emwinShRun(object $t, string $file, array $extraEnv = []): ProcessResult
{
    return Process::path(base_path())->env($extraEnv + [
        'PATH' => $t->bin.':/usr/bin:/bin',
        'EMWIN_FILES_ROOT' => $t->root,
        'EMWIN_WEBHOOK_URL' => 'http://laravel.test/api/pipeline/emwin-products',
        'PIPELINE_WEBHOOK_TOKEN' => 'secret-token',
        'EMWIN_HEARTBEAT_FILE' => $t->dir.'/heartbeat',
    ])->run(['sh', 'docker/emwin-byteblaster/pan-notify.sh', $file]);
}

function emwinShPosts(object $t): array
{
    $log = $t->dir.'/curl.log';
    if (! File::exists($log)) {
        return [];
    }

    // The fake curl writes one JSON body per call on its own line.
    return array_values(array_filter(array_map(
        fn ($line) => str_starts_with($line, '{"path"') ? json_decode($line, true)['path'] : null,
        explode("\n", File::get($log)),
    )));
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/emwin-pan-'.uniqid();
    $this->root = $this->dir.'/files';
    $this->bin = $this->dir.'/bin';
    File::makeDirectory($this->root.'/txt/kbox', recursive: true);
    File::makeDirectory($this->root.'/zip/kbox', recursive: true);
    File::makeDirectory($this->bin);

    // Fake curl: log every argument on its own line, fail when FAKE_CURL_FAIL is set.
    File::put($this->bin.'/curl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"".$this->dir."/curl.log\"\n[ -n \"\${FAKE_CURL_FAIL:-}\" ] && exit 22\nexit 0\n");
    File::put($this->bin.'/logger', "#!/bin/sh\necho \"\$*\" >> \"".$this->dir."/logger.log\"\n");
    chmod($this->bin.'/curl', 0755);
    chmod($this->bin.'/logger', 0755);
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('the script is valid POSIX sh', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/emwin-byteblaster/pan-notify.sh'])->successful())->toBeTrue();
});

test('a zip with one text member is unzipped to a unique delivered file and posted once with the bearer token', function () {
    emwinShMakeZip($this->root.'/zip/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => "FXUS61 KBOX 191622\nAFDBOX\nDiscussion text"]);

    $result = emwinShRun($this, $this->root.'/zip/kbox/AFDBOX.ZIP');

    $posts = emwinShPosts($this);
    expect($result->exitCode())->toBe(0)
        ->and($posts)->toHaveCount(1)
        ->and($posts[0])->toMatch('#^delivered/zip/kbox/AFDBOX_\d{8}T\d{6}_\d+_\d+\.txt$#')
        ->and(File::get($this->root.'/'.$posts[0]))->toContain('Discussion text')
        ->and(File::get($this->dir.'/curl.log'))->toContain('Authorization: Bearer secret-token')
        ->and(File::exists($this->dir.'/heartbeat'))->toBeTrue();
});

test('running twice on the same source name produces two distinct delivered paths', function () {
    emwinShMakeZip($this->root.'/zip/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => 'one']);
    emwinShRun($this, $this->root.'/zip/kbox/AFDBOX.ZIP');
    emwinShMakeZip($this->root.'/zip/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => 'two']);
    emwinShRun($this, $this->root.'/zip/kbox/AFDBOX.ZIP');

    $posts = emwinShPosts($this);
    expect($posts)->toHaveCount(2)->and($posts[0])->not->toBe($posts[1])
        ->and(File::get($this->root.'/'.$posts[0]))->toBe('one')
        ->and(File::get($this->root.'/'.$posts[1]))->toBe('two');
});

test('a plain txt product is posted by its own relative path, not copied, and the source stays', function () {
    File::put($this->root.'/txt/kbox/afdbox.abc123.txt', 'plain product');

    emwinShRun($this, $this->root.'/txt/kbox/afdbox.abc123.txt');

    expect(emwinShPosts($this))->toBe(['txt/kbox/afdbox.abc123.txt'])
        ->and(File::exists($this->root.'/delivered'))->toBeFalse()
        ->and(File::get($this->root.'/txt/kbox/afdbox.abc123.txt'))->toBe('plain product');
});

test('a zip with several members posts each text member and ignores non-text members', function () {
    emwinShMakeZip($this->root.'/zip/kbox/MULTI.ZIP', ['A.TXT' => 'a', 'B.txt' => 'b', 'PIC.GIF' => 'gif-bytes']);

    emwinShRun($this, $this->root.'/zip/kbox/MULTI.ZIP');

    expect(emwinShPosts($this))->toHaveCount(2);
});

test('images and other file types are ignored', function () {
    File::put($this->root.'/txt/kbox/RADAR.GIF', 'gif');

    emwinShRun($this, $this->root.'/txt/kbox/RADAR.GIF');

    expect(emwinShPosts($this))->toBe([]);
});

test('a corrupt zip is logged as an error, posts nothing, exits 0 and leaves no temp files', function () {
    File::put($this->root.'/zip/kbox/BAD.ZIP', random_bytes(200));

    $result = emwinShRun($this, $this->root.'/zip/kbox/BAD.ZIP');

    expect($result->exitCode())->toBe(0)
        ->and(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('BAD.ZIP')
        ->and(File::exists($this->root.'/delivered'))->toBeFalse();
});

test('an encrypted zip is skipped with an error', function () {
    emwinShMakeZip($this->root.'/zip/kbox/SECRET.ZIP', ['S.TXT' => 'secret'], 'pw');

    $result = emwinShRun($this, $this->root.'/zip/kbox/SECRET.ZIP');

    expect($result->exitCode())->toBe(0)->and(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('SECRET.ZIP');
});

test('an oversized or over-populated zip is refused before extraction', function () {
    emwinShMakeZip($this->root.'/zip/kbox/BIG.ZIP', ['BIG.TXT' => str_repeat('x', 500)]);
    emwinShRun($this, $this->root.'/zip/kbox/BIG.ZIP', ['EMWIN_MAX_UNZIP_BYTES' => '100']);

    emwinShMakeZip($this->root.'/zip/kbox/MANY.ZIP', ['1.TXT' => 'a', '2.TXT' => 'b', '3.TXT' => 'c']);
    emwinShRun($this, $this->root.'/zip/kbox/MANY.ZIP', ['EMWIN_MAX_UNZIP_MEMBERS' => '2']);

    expect(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('BIG.ZIP')->toContain('MANY.ZIP');
});

test('a zip with no text members logs an error and posts nothing', function () {
    emwinShMakeZip($this->root.'/zip/kbox/NOTEXT.ZIP', ['PIC.GIF' => 'gif']);

    emwinShRun($this, $this->root.'/zip/kbox/NOTEXT.ZIP');

    expect(emwinShPosts($this))->toBe([])->and(File::get($this->dir.'/logger.log'))->toContain('NOTEXT.ZIP');
});

test('a hostile member path cannot write outside the delivered directory', function () {
    emwinShMakeZip($this->root.'/zip/kbox/EVIL.ZIP', ['../../evil.txt' => 'pwned']);

    emwinShRun($this, $this->root.'/zip/kbox/EVIL.ZIP');

    expect(File::exists($this->dir.'/evil.txt'))->toBeFalse()
        ->and(File::exists($this->root.'/evil.txt'))->toBeFalse();
});

test('a text member inside a subdirectory of the zip is flattened and delivered', function () {
    emwinShMakeZip($this->root.'/zip/kbox/NESTED.ZIP', ['deep/er/NESTED.TXT' => 'nested text']);

    emwinShRun($this, $this->root.'/zip/kbox/NESTED.ZIP');

    $posts = emwinShPosts($this);
    expect($posts)->toHaveCount(1)
        ->and($posts[0])->toStartWith('delivered/zip/kbox/NESTED_')
        ->and(File::get($this->root.'/'.$posts[0]))->toBe('nested text');
});

test('a failed POST is logged and does not crash or update the heartbeat', function () {
    File::put($this->root.'/txt/kbox/rwrbox.txt', 'x');

    $result = emwinShRun($this, $this->root.'/txt/kbox/rwrbox.txt', ['FAKE_CURL_FAIL' => '1']);

    expect($result->exitCode())->toBe(0)
        ->and(File::exists($this->dir.'/heartbeat'))->toBeFalse()
        ->and(File::get($this->dir.'/logger.log'))->toContain('rwrbox');
});

test('a bad zip followed by a good one still delivers the good one', function () {
    File::put($this->root.'/zip/kbox/BAD.ZIP', random_bytes(100));
    emwinShMakeZip($this->root.'/zip/kbox/GOOD.ZIP', ['GOOD.TXT' => 'good']);

    emwinShRun($this, $this->root.'/zip/kbox/BAD.ZIP');
    emwinShRun($this, $this->root.'/zip/kbox/GOOD.ZIP');

    expect(emwinShPosts($this))->toHaveCount(1);
});

test('file names with unsafe characters are refused rather than put into JSON', function () {
    File::put($this->root.'/txt/kbox/we"ird.txt', 'x');

    emwinShRun($this, $this->root.'/txt/kbox/we"ird.txt');

    expect(emwinShPosts($this))->toBe([]);
});

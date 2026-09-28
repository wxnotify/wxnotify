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
        'EMWIN_WEBHOOK_URL' => 'http://pipeline-api:8080/api/pipeline/emwin-products',
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

/**
 * Replaces the fake curl with one that snapshots the product file at the moment of the POST,
 * so a test can prove what the pipeline would have read, not just what is on disk afterwards.
 */
function emwinShSnapshotCurl(object $t): void
{
    File::put($t->bin.'/curl', "#!/bin/sh\ncp \"\$SNAPSHOT_SRC\" \"\$SNAPSHOT_DEST\"\nprintf '%s\\n' \"\$@\" >> \"".$t->dir."/curl.log\"\nexit 0\n");
    chmod($t->bin.'/curl', 0755);
}

test('doubled CR CR LF line endings are reduced to single line breaks before the product is posted', function () {
    $file = $this->root.'/txt/kbox/torbox.abc.txt';
    File::put($file, "WFUS53 KBOU 191622\r\r\nTORBOU\r\r\n\r\r\nBULLETIN\r\r\nTornado Warning\r\r\n");
    emwinShSnapshotCurl($this);

    emwinShRun($this, $file, ['SNAPSHOT_SRC' => $file, 'SNAPSHOT_DEST' => $this->dir.'/snapshot.txt']);

    $expected = "WFUS53 KBOU 191622\nTORBOU\n\nBULLETIN\nTornado Warning\n";
    expect(emwinShPosts($this))->toBe(['txt/kbox/torbox.abc.txt'])
        ->and(File::get($this->dir.'/snapshot.txt'))->toBe($expected)
        ->and(File::get($file))->toBe($expected);
});

test('plain CR LF line endings become single line breaks too', function () {
    $file = $this->root.'/txt/kbox/crlf.txt';
    File::put($file, "LINE ONE\r\nLINE TWO\r\n");

    emwinShRun($this, $file);

    expect(File::get($file))->toBe("LINE ONE\nLINE TWO\n");
});

test('only line-ending carriage returns are removed, everything else is left as it was', function () {
    $file = $this->root.'/txt/kbox/mixed.txt';
    File::put($file, "\x01A\rB\r\r\n  indented   spaces\t tab\r\r\n\r\r\n\r\r\nlast line without a break\x03");

    emwinShRun($this, $file);

    expect(File::get($file))->toBe("\x01A\rB\n  indented   spaces\t tab\n\n\nlast line without a break\x03");
});

test('a product with no carriage returns is not rewritten', function () {
    $file = $this->root.'/txt/kbox/clean.txt';
    File::put($file, "already\nclean\n");
    touch($file, 1_000_000_000);
    clearstatcache();

    emwinShRun($this, $file);

    clearstatcache();
    expect(File::get($file))->toBe("already\nclean\n")
        ->and(filemtime($file))->toBe(1_000_000_000)
        ->and(emwinShPosts($this))->toBe(['txt/kbox/clean.txt']);
});

test('a text member extracted from a zip is normalized in its delivered copy', function () {
    emwinShMakeZip($this->root.'/zip/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => "FXUS61 KBOX 191622\r\r\nAFDBOX\r\r\n\r\r\nDiscussion text\r\r\n"]);

    emwinShRun($this, $this->root.'/zip/kbox/AFDBOX.ZIP');

    $posts = emwinShPosts($this);
    expect($posts)->toHaveCount(1)
        ->and(File::get($this->root.'/'.$posts[0]))->toBe("FXUS61 KBOX 191622\nAFDBOX\n\nDiscussion text\n");
});

test('if normalizing fails the failure is logged and the product is still delivered', function () {
    $file = $this->root.'/txt/kbox/stuck.txt';
    File::put($file, "A\r\r\nB\r\r\n");
    File::put($this->bin.'/sed', "#!/bin/sh\nexit 1\n");
    chmod($this->bin.'/sed', 0755);

    $result = emwinShRun($this, $file);

    expect($result->exitCode())->toBe(0)
        ->and(emwinShPosts($this))->toBe(['txt/kbox/stuck.txt'])
        ->and(File::get($this->dir.'/logger.log'))->toContain('could not normalize the line endings of txt/kbox/stuck.txt')
        ->and(File::get($file))->toBe("A\r\r\nB\r\r\n");
});

test('a file with an unsafe name is refused before it is touched', function () {
    $file = $this->root.'/txt/kbox/we"ird.txt';
    File::put($file, "A\r\r\nB\r\r\n");

    emwinShRun($this, $file);

    expect(emwinShPosts($this))->toBe([])
        ->and(File::get($file))->toBe("A\r\r\nB\r\r\n");
});

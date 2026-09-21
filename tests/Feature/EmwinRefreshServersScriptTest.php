<?php

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function emwinShRefresh(array $env): ProcessResult
{
    return Process::path(base_path())->env($env + ['PATH' => '/usr/bin:/bin'])
        ->run(['sh', 'docker/emwin-byteblaster/refresh-servers.sh']);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/emwin-refresh-'.uniqid();
    File::makeDirectory($this->dir);
    $this->conf = $this->dir.'/servers.conf';
    $this->env = [
        'EMWIN_SERVERS_URL' => 'file://'.base_path('tests/Fixtures/emwin/server-status.html'),
        'EMWIN_SERVERS_CONF' => $this->conf,
        'EMWIN_MIN_SERVERS' => '3',
    ];
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('the script is valid POSIX sh', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/emwin-byteblaster/refresh-servers.sh'])->successful())->toBeTrue();
});

test('it writes IP<TAB>port lines, drops bad rows and duplicates, and reports changed (10)', function () {
    File::put($this->conf, "1.1.1.1\t2211\n");

    $result = emwinShRefresh($this->env);

    expect($result->exitCode())->toBe(10)
        ->and(File::get($this->conf))->toBe("149.154.51.234\t2211\n71.82.31.73\t2211\nemwin.ewarn.org\t2211\nictstormwatch.ddns.net\t1000\n");
});

test('an identical list reports unchanged (0) and leaves the file alone', function () {
    File::put($this->conf, "149.154.51.234\t2211\n71.82.31.73\t2211\nemwin.ewarn.org\t2211\nictstormwatch.ddns.net\t1000\n");
    $before = fileinode($this->conf);

    expect(emwinShRefresh($this->env)->exitCode())->toBe(0)
        ->and(fileinode($this->conf))->toBe($before);
});

test('garbage or too few servers fail (1) and never touch the existing list', function () {
    File::put($this->conf, "9.9.9.9\t2211\n");
    File::put($this->dir.'/garbage.html', '<html>maintenance</html>');

    expect(emwinShRefresh(['EMWIN_SERVERS_URL' => 'file://'.$this->dir.'/garbage.html'] + $this->env)->exitCode())->toBe(1)
        ->and(File::get($this->conf))->toBe("9.9.9.9\t2211\n");

    expect(emwinShRefresh(['EMWIN_MIN_SERVERS' => '10'] + $this->env)->exitCode())->toBe(1)
        ->and(File::get($this->conf))->toBe("9.9.9.9\t2211\n");
});

test('an unreachable page fails (1), keeps the list, and leaves no temp files behind', function () {
    File::put($this->conf, "9.9.9.9\t2211\n");

    $result = emwinShRefresh(['EMWIN_SERVERS_URL' => 'file:///nonexistent/page.html'] + $this->env);

    expect($result->exitCode())->toBe(1)
        ->and(File::get($this->conf))->toBe("9.9.9.9\t2211\n")
        ->and(collect(File::files($this->dir))->map->getFilename()->all())->toBe(['servers.conf']);
});

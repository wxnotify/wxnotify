# EMWIN Byteblaster Container Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A multi-arch `emwin` Docker service running Npemwin that downloads EMWIN products from community Byteblaster servers, unzips them, and delivers each text product to a new Laravel endpoint that ingests it with cross-feed dedup against NWWS, plus Pipeline Control wiring and a feed heartbeat.

**Architecture:** npemwind (Tcl, foreground) writes received files under `/var/npemwin/files/text/`; its panfilter calls `pan-notify.sh` per file, which unzips (or copies) each text product to a unique file under `delivered/` in the shared `emwin-products` volume and POSTs the relative path to `/api/pipeline/emwin-products`. Laravel resolves the path under a read-only mount, derives identity from the WMO header (not the filename), de-duplicates on a normalized header key shared with the NWWS endpoint, and records a per-feed heartbeat. A separate script scrapes the community server list into `servers.conf` and restarts the daemon only when the list changed.

**Tech Stack:** Debian 12 slim, npemwin 3.0.1p1 (vendored .deb per arch via BuildKit `TARGETARCH`), rsyslog, POSIX sh + awk + curl + unzip; Laravel 13, Filament v5, Pest.

**Spec:** `docs/superpowers/specs/2026-09-19-emwin-byteblaster-container-design.md`

**Standing rules for every task:**
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed` against the dev DB. Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`. (That container has `dash` as `sh`, `mawk`, `unzip`, `zip`, `logger`, `curl` — the same userland the emwin image uses, so shell-script tests are meaningful there.)
- Read `.ai/rules/index.md` and every rule file matching the paths you edit. Pest helper function names are global — prefix helpers (`emwinSh…`, `emwinApi…`).
- Run `vendor/bin/pint --dirty --format agent` on the host before each PHP commit. Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- The user's sample files live in the untracked `temp/` directory (`features.conf`, `panfilter.conf`, `panfilter.rc`, `servers.conf`, `npemwin_3.0.1p1-1_arm64.deb`). Copy from it; never `git add temp/`.
- Shell scripts must be POSIX `sh` (dash) and pass `sh -n`. They are executable (`chmod +x`), LF line endings.
- The dev machine is arm64 (Apple silicon); production is amd64. Do not add a `platform:` pin; the image picks its `.deb` from `TARGETARCH`.

---

## Plan adjustments from the Task 1 spike (these SUPERSEDE conflicting text in later tasks)

Verified facts are in the spec's "Verified facts (spike)" section. Consequences:

1. **Storage root is `/var/npemwin/files`, not `.../files/text`.** npemwind stores text products at `/var/npemwin/files/txt/<cccc>/<product>.<md5>.txt` (lowercase). Everywhere the plan says `/var/npemwin/files/text` / `EMWIN_FILES_ROOT` default / the Compose volume target, use **`/var/npemwin/files`**. The volume is `emwin-products:/var/npemwin/files`; Laravel mounts it read-only at `/emwin-products`; posted paths look like `txt/kbou/afdbou.<md5>.txt`.
2. **npemwind already unzips and strips control characters itself** (its `qrunner`), so text products arrive as plain `.txt`, uniquely named by content MD5 (nothing is overwritten; identical retransmissions are dropped before panfilter). Therefore `pan-notify.sh` **delivers `.txt` files directly by their relative path — no copy**. The user still requires an unzip process, so the **`.zip` branch stays as a defensive path** for any zip that reaches panfilter: unzip (guards and error handling unchanged) into unique files under `$ROOT/delivered/<reldir of the zip>/` and deliver those. Task 3's tests change accordingly: a plain `.txt` under `txt/kbox/` posts the path `txt/kbox/<name>` (source untouched, no `delivered/` copy); the "two runs give distinct paths" test applies to **zip members** (via unique naming), and all zip/unzip tests stay as written. Only `.txt` and `.zip` are acted on; everything else is ignored.
3. **Dockerfile:** the package creates and owns `/var/npemwin/*` and the `noaaport` user; only `mkdir -p /var/npemwin/files/delivered` (owned by noaaport) is needed. Keep the `id noaaport ||` fallback harmless.
4. **`entrypoint.sh`:** the daemon's process pattern is `npemwind -F` — use `pkill -TERM -f 'npemwind -F'` and `pgrep -f 'npemwind -F'`. SIGHUP reload is unreliable, so keep stop-and-restart. The retention loop now only prunes `delivered/` (zip members). Expect a nonzero exit (status 1) after SIGTERM; the restart flag distinguishes an intentional restart.
5. Laravel tasks are unaffected (posted paths are relative to the mount; `file_path` stays `emwin/<path>`).

## File Structure

- `docker/emwin/Dockerfile`, `entrypoint.sh`, `pan-notify.sh`, `refresh-servers.sh`, `healthcheck.sh`, `rsyslog.conf`
- `docker/emwin/site/{features.conf,panfilter.conf,panfilter.rc}`, `docker/emwin/servers.conf.fallback`
- `docker/emwin/packages/{npemwin_3.0.1p1-1_arm64.deb,npemwin_3.0.1p1-1_amd64.deb,SHA256SUMS}`
- `compose.yaml` (new `emwin` service, `emwin-products` volume, read-only mount in `laravel.test`), `.env.example`, `config/services.php`
- `database/migrations/2026_09_19_100001_add_source_and_wmo_header_key_to_ingested_products_table.php`; `app/Models/IngestedProduct.php`
- `app/Actions/Pipeline/ExtractWmoHeaderIdentity.php`, `app/Actions/Pipeline/IngestOncePerWmoHeader.php`, `app/Support/Pipeline/PipelineHeartbeat.php`
- `app/Http/Controllers/IngestEmwinProductController.php`, `IngestNwwsProductController.php` (modified), `routes/api.php`
- `app/Filament/Admin/Pages/PipelineControl.php` + its Blade view
- Tests: `tests/Feature/EmwinRefreshServersScriptTest.php`, `EmwinPanNotifyScriptTest.php`, `ExtractWmoHeaderIdentityTest.php`, `IngestEmwinProductControllerTest.php`, `PipelineControlTest.php` (modified), `IngestNwwsProductControllerTest.php` (modified), fixture `tests/Fixtures/emwin/server-status.html`

---

### Task 1: Vendored packages, Dockerfile skeleton, and the verification spike

**Files:** Create `docker/emwin/packages/*`, `docker/emwin/Dockerfile`, `docker/emwin/site/*`, `docker/emwin/servers.conf.fallback`.

- [ ] **Step 1: Vendor the packages.**
```bash
mkdir -p docker/emwin/packages docker/emwin/site
cp temp/npemwin_3.0.1p1-1_arm64.deb docker/emwin/packages/
curl -fsSk -o docker/emwin/packages/npemwin_3.0.1p1-1_amd64.deb \
  https://www.noaaport.net/software/packages/npemwin-3.0.1p1/packages/debian-11/amd64/npemwin_3.0.1p1-1_amd64.deb
( cd docker/emwin/packages && shasum -a 256 *.deb > SHA256SUMS && cat SHA256SUMS )
```
Expected: the arm64 line is `006635decab0147de8a786bedce26047da3cc9e49fba7265a89b9954f7923a76  npemwin_3.0.1p1-1_arm64.deb`. (`-k` is needed only because noaaport.net serves an incomplete TLS chain; integrity is guaranteed by the recorded checksum.) Confirm both are Debian archives: `ar t docker/emwin/packages/*.deb` lists `debian-binary control.tar.* data.tar.*`.

- [ ] **Step 2: Copy site configuration** from the user's samples:
```bash
cp temp/features.conf temp/panfilter.conf docker/emwin/site/
cp temp/servers.conf docker/emwin/servers.conf.fallback
```
Create `docker/emwin/site/panfilter.rc` (adapted from `temp/panfilter.rc`: same condition, our script, no file logging):
```tcl
# Call the delivery hook for every file received. It ignores non-text files itself.
lappend cond {1}
lappend action {
  exec /pan-notify.sh $rc(fpath);
}
```

- [ ] **Step 3: Dockerfile skeleton** (`docker/emwin/Dockerfile`; build context is the repo root):
```dockerfile
FROM debian:12-slim

ARG TARGETARCH
ARG NPEMWIN_VERSION=3.0.1p1-1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip rsyslog procps tini util-linux \
        tcl tcllib libdb5.3 libwrap0 gnuplot-nox \
    && rm -rf /var/lib/apt/lists/*

COPY docker/emwin/packages/ /tmp/packages/
RUN cd /tmp/packages \
    && grep "npemwin_${NPEMWIN_VERSION}_${TARGETARCH}.deb" SHA256SUMS | sha256sum -c - \
    && apt-get update \
    && apt-get install -y --no-install-recommends "./npemwin_${NPEMWIN_VERSION}_${TARGETARCH}.deb" \
    && rm -rf /var/lib/apt/lists/* /tmp/packages

RUN id noaaport >/dev/null 2>&1 || (groupadd -r noaaport && useradd -r -g noaaport -d /var/npemwin -s /usr/sbin/nologin noaaport)
RUN mkdir -p /var/npemwin/files/text/delivered /usr/local/etc/npemwin/site \
    && chown -R noaaport:noaaport /var/npemwin \
    && chmod -R a+rX /var/npemwin

COPY docker/emwin/site/ /usr/local/etc/npemwin/site/
COPY docker/emwin/servers.conf.fallback /usr/local/etc/npemwin/servers.conf
```
(The remaining `COPY`/`ENTRYPOINT` lines are added in Task 4.)

- [ ] **Step 4: Build for the local arch and inspect.**
```bash
docker build -f docker/emwin/Dockerfile -t emwin-spike .
docker run --rm --entrypoint sh emwin-spike -c 'id noaaport; ls -la /usr/local/sbin /usr/local/etc/npemwin /usr/local/etc/npemwin/site /var/npemwin; head -3 /usr/local/sbin/npemwind; npemwinversion 2>&1 | head -3'
```
- [ ] **Step 5: The spike — run npemwind for real and record facts.** Start a throwaway container (`docker run -d --name emwin-spike --entrypoint sh emwin-spike -c 'rsyslogd; sleep 3; runuser -u noaaport -- /usr/local/sbin/npemwind -F'`) — if `rsyslogd`/`runuser` fail adapt and note. Let it run 3–5 minutes against the baked-in `servers.conf`, then inspect with `docker exec`/`docker logs`:
  1. Does it start with these `site/` files, connect to a server, and receive files? What does it log and where (`/var/log/*`, syslog facility/ident)?
  2. What files appear under `/var/npemwin/files/text/[cccc]/` — exact names, extension case, **are they `.ZIP` or plain `.TXT`** (does npemwind unzip them itself)? Show 3 `ls -l` samples and, for a zip, `unzip -l`; for a text file, its first 5 lines (does it start with the WMO heading `TTAAII CCCC DDHHMM`?).
  3. Does a same-named file get overwritten by a later issuance (watch one repeating name such as an AFD/RWR file)?
  4. Does panfilter fire? Temporarily replace `/usr/local/etc/npemwin/site/panfilter.rc` in the running container with an action that appends `$rc(fpath)` to `/tmp/pan.log`, restart, and list which `$rc(...)` variables exist (look in `/usr/local/share` and the installed Tcl sources: `grep -rn 'rc(f' /usr/local/share /usr/local/lib 2>/dev/null | head`).
  5. Which process name does the daemon have (`ps -eo pid,args | grep npemwind`) — needed for `pkill -f` in Task 4.
  6. Does it re-read `servers.conf` on a signal (try `kill -HUP`; watch the log), or only at start?
  7. Is the web UI answering on 8016 inside the container (`curl -s localhost:8016 | head`)?
  8. Does the package's postinst/startup require anything else (ownership of `/var/npemwin`, a pid dir)?
  Stop and remove the spike container/image afterwards.
- [ ] **Step 6: Record the findings** by appending a section `## Verified facts (spike, 2026-09-19)` to `docs/superpowers/specs/2026-09-19-emwin-byteblaster-container-design.md` listing the answers above (tables/bullets, with the sample names and heading lines). If any answer contradicts the plan (e.g. npemwind already unzips; different process name; panfilter variables), also note "Plan adjustments:" and follow the adjustment in later tasks (e.g. the name pattern used in Task 3's ext handling). If the container cannot run at all, STOP and report BLOCKED with logs.
- [ ] **Step 7: Commit** `docker/emwin/{Dockerfile,site,servers.conf.fallback,packages}` and the spec update: `git add docker/emwin docs/superpowers/specs/2026-09-19-emwin-byteblaster-container-design.md && git commit -m "Add the EMWIN image skeleton, vendored npemwin packages, and spike findings"`.

---

### Task 2: `refresh-servers.sh`

**Files:** Create `docker/emwin/refresh-servers.sh`, `tests/Fixtures/emwin/server-status.html`, `tests/Feature/EmwinRefreshServersScriptTest.php`.

Behavior: exit `0` = list unchanged, `10` = list changed (file replaced), `1` = failed (file untouched). Env overrides for tests: `EMWIN_SERVERS_URL` (curl-supported, so `file://` works), `EMWIN_SERVERS_CONF`, `EMWIN_MIN_SERVERS` (default 3).

- [ ] **Step 1: Fixture** `tests/Fixtures/emwin/server-status.html` (mirrors the live page structure; verified against `https://weathermessage.net/Support/EMWINInternetStatus.aspx` on 2026-09-19):
```html
<html><body>
<table style="width: 100%;"><tr><td style="text-align: center">Current EMWIN Internet Servers - 6</td></tr><tr><td>
<table id="dnn_ctr468_WxMesgByteStatus_grdData">
<tr><th scope="col">Ip Address</th><th scope="col">Server Name</th><th scope="col">Port</th></tr><tr>
<td style="width:25%;">149.154.51.234</td><td style="width:35%;">&nbsp;</td><td align="center" style="width:15%;">2211</td>
</tr><tr>
<td style="width:25%;">71.82.31.73</td><td style="width:35%;">emwin.weathermessage.com</td><td align="center" style="width:15%;">2211</td>
</tr><tr>
<td style="width:25%;">71.82.31.73</td><td style="width:35%;">emwin.weathermessage.com</td><td align="center" style="width:15%;">2211</td>
</tr><tr>
<td style="width:25%;">emwin.ewarn.org</td><td style="width:35%;">emwin.ewarn.org</td><td align="center" style="width:15%;">2211</td>
</tr><tr>
<td style="width:25%;">ictstormwatch.ddns.net</td><td style="width:35%;">ictstormwatch.ddns.net</td><td align="center" style="width:15%;">1000</td>
</tr><tr>
<td style="width:25%;">10.0.0.1</td><td style="width:35%;">bad-port.example</td><td align="center" style="width:15%;">99999</td>
</tr><tr>
<td style="width:25%;">only two cells</td><td>x</td>
</tr>
</table></td></tr></table>
</body></html>
```
Expected parse: `149.154.51.234⇥2211`, `71.82.31.73⇥2211`, `emwin.ewarn.org⇥2211`, `ictstormwatch.ddns.net⇥1000` (4 lines; duplicate collapsed, bad port and 2-cell rows dropped).

- [ ] **Step 2: Failing tests** `tests/Feature/EmwinRefreshServersScriptTest.php`:
```php
<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function emwinShRefresh(array $env): Illuminate\Process\ProcessResult
{
    return Process::path(base_path())->env($env + ['PATH' => '/usr/bin:/bin'])
        ->run(['sh', 'docker/emwin/refresh-servers.sh']);
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
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/emwin/refresh-servers.sh'])->successful())->toBeTrue();
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
```
Run: expect FAIL (script missing).

- [ ] **Step 3: Implement** `docker/emwin/refresh-servers.sh`:
```sh
#!/bin/sh
# Scrapes the community EMWIN Byteblaster server list into npemwin's servers.conf.
# Exit codes: 0 unchanged, 10 changed (file replaced), 1 failed (file untouched).
set -u

PAGE_URL="${EMWIN_SERVERS_URL:-https://weathermessage.net/Support/EMWINInternetStatus.aspx}"
SERVERS_CONF="${EMWIN_SERVERS_CONF:-/usr/local/etc/npemwin/servers.conf}"
MIN_SERVERS="${EMWIN_MIN_SERVERS:-3}"

log() {
    level=$1
    shift
    if command -v logger >/dev/null 2>&1; then
        logger -t emwin-servers -p "user.$level" -- "$*"
    fi
    echo "emwin-servers [$level] $*"
}

tmp=$(mktemp "${SERVERS_CONF}.XXXXXX") || { log err "cannot create a temp file next to $SERVERS_CONF"; exit 1; }
page="$tmp.page"
trap 'rm -f "$tmp" "$page"' EXIT

if ! curl -fsS --max-time 30 "$PAGE_URL" -o "$page" 2>"$tmp.err"; then
    log err "could not fetch $PAGE_URL: $(cat "$tmp.err")"
    rm -f "$tmp.err"
    exit 1
fi
rm -f "$tmp.err"

# One table row per line, then pull the three <td> cells out of each: address/host, name, port.
tr '\n\r\t' '   ' < "$page" \
    | sed 's#</tr>#</tr>\
#g' \
    | awk '
        {
            line = $0; n = 0
            while (match(line, /<td[^>]*>[^<]*<\/td>/)) {
                cell = substr(line, RSTART, RLENGTH); line = substr(line, RSTART + RLENGTH)
                sub(/^<td[^>]*>/, "", cell); sub(/<\/td>$/, "", cell)
                gsub(/&nbsp;/, "", cell); gsub(/^ +| +$/, "", cell)
                c[++n] = cell
            }
            if (n == 3 && c[1] ~ /^[A-Za-z0-9.-]+$/ && c[3] ~ /^[0-9]+$/ && c[3] + 0 >= 1 && c[3] + 0 <= 65535) {
                print c[1] "\t" c[3]
            }
        }' \
    | awk '!seen[$0]++' > "$tmp"

count=$(wc -l < "$tmp" | tr -d ' ')
if [ "$count" -lt "$MIN_SERVERS" ]; then
    log err "found only $count servers (need at least $MIN_SERVERS); keeping the current list"
    exit 1
fi

if [ -f "$SERVERS_CONF" ] && cmp -s "$tmp" "$SERVERS_CONF"; then
    log info "server list unchanged ($count servers)"
    exit 0
fi

chmod 644 "$tmp"
mv "$tmp" "$SERVERS_CONF"
log info "server list updated ($count servers)"
exit 10
```
`chmod +x docker/emwin/refresh-servers.sh`.

- [ ] **Step 4: Run tests** — PASS. Also run the script once against the live page from the host to sanity-check: `EMWIN_SERVERS_CONF=/tmp/servers.test sh docker/emwin/refresh-servers.sh; cat /tmp/servers.test` (expect ~14 lines; delete `/tmp/servers.test`).
- [ ] **Step 5: Commit** `git add docker/emwin/refresh-servers.sh tests/Fixtures/emwin tests/Feature/EmwinRefreshServersScriptTest.php && git commit -m "Add the EMWIN server-list refresher"`.

---

### Task 3: `pan-notify.sh` (unzip, unique copies, delivery)

**Files:** Create `docker/emwin/pan-notify.sh`, `tests/Feature/EmwinPanNotifyScriptTest.php`.

Adjust file-name handling to the spike's findings if they differ (e.g. if npemwind stores plain `.TXT`, the `.TXT` branch is the common path; both branches must still be implemented and tested).

Contract: `pan-notify.sh <absolute path of a file npemwind just stored>`. Env: `EMWIN_FILES_ROOT` (default `/var/npemwin/files/text`), `EMWIN_WEBHOOK_URL`, `PIPELINE_WEBHOOK_TOKEN`, `EMWIN_MAX_UNZIP_BYTES` (5242880), `EMWIN_MAX_UNZIP_MEMBERS` (50), `EMWIN_HEARTBEAT_FILE` (`/tmp/emwin-last-product`). Always exits 0.

- [ ] **Step 1: Failing tests** `tests/Feature/EmwinPanNotifyScriptTest.php`:
```php
<?php

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

function emwinShRun(object $t, string $file, array $extraEnv = []): Illuminate\Process\ProcessResult
{
    return Process::path(base_path())->env($extraEnv + [
        'PATH' => $t->bin.':/usr/bin:/bin',
        'EMWIN_FILES_ROOT' => $t->root,
        'EMWIN_WEBHOOK_URL' => 'http://laravel.test/api/pipeline/emwin-products',
        'PIPELINE_WEBHOOK_TOKEN' => 'secret-token',
        'EMWIN_HEARTBEAT_FILE' => $t->dir.'/heartbeat',
    ])->run(['sh', 'docker/emwin/pan-notify.sh', $file]);
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
    $this->root = $this->dir.'/text';
    $this->bin = $this->dir.'/bin';
    File::makeDirectory($this->root.'/kbox', recursive: true);
    File::makeDirectory($this->bin);

    // Fake curl: log every argument on its own line, fail when FAKE_CURL_FAIL is set.
    File::put($this->bin.'/curl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"".$this->dir."/curl.log\"\n[ -n \"\${FAKE_CURL_FAIL:-}\" ] && exit 22\nexit 0\n");
    File::put($this->bin.'/logger', "#!/bin/sh\necho \"\$*\" >> \"".$this->dir."/logger.log\"\n");
    chmod($this->bin.'/curl', 0755);
    chmod($this->bin.'/logger', 0755);
});

afterEach(fn () => File::deleteDirectory($this->dir));

test('the script is valid POSIX sh', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/emwin/pan-notify.sh'])->successful())->toBeTrue();
});

test('a zip with one text member is unzipped to a unique delivered file and posted once with the bearer token', function () {
    emwinShMakeZip($this->root.'/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => "FXUS61 KBOX 191622\nAFDBOX\nDiscussion text"]);

    $result = emwinShRun($this, $this->root.'/kbox/AFDBOX.ZIP');

    $posts = emwinShPosts($this);
    expect($result->exitCode())->toBe(0)
        ->and($posts)->toHaveCount(1)
        ->and($posts[0])->toMatch('#^delivered/kbox/AFDBOX_\d{8}T\d{6}_\d+_\d+\.txt$#')
        ->and(File::get($this->root.'/'.$posts[0]))->toContain('Discussion text')
        ->and(File::get($this->dir.'/curl.log'))->toContain('Authorization: Bearer secret-token')
        ->and(File::exists($this->dir.'/heartbeat'))->toBeTrue();
});

test('running twice on the same source name produces two distinct delivered paths', function () {
    emwinShMakeZip($this->root.'/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => 'one']);
    emwinShRun($this, $this->root.'/kbox/AFDBOX.ZIP');
    emwinShMakeZip($this->root.'/kbox/AFDBOX.ZIP', ['AFDBOX.TXT' => 'two']);
    emwinShRun($this, $this->root.'/kbox/AFDBOX.ZIP');

    $posts = emwinShPosts($this);
    expect($posts)->toHaveCount(2)->and($posts[0])->not->toBe($posts[1])
        ->and(File::get($this->root.'/'.$posts[0]))->toBe('one')
        ->and(File::get($this->root.'/'.$posts[1]))->toBe('two');
});

test('a plain TXT file is copied to a unique delivered path and posted', function () {
    File::put($this->root.'/kbox/RWRBOX.TXT', 'plain product');

    emwinShRun($this, $this->root.'/kbox/RWRBOX.TXT');

    $posts = emwinShPosts($this);
    expect($posts)->toHaveCount(1)->and($posts[0])->toStartWith('delivered/kbox/RWRBOX_')
        ->and(File::get($this->root.'/'.$posts[0]))->toBe('plain product')
        ->and(File::exists($this->root.'/kbox/RWRBOX.TXT'))->toBeTrue();
});

test('a zip with several members posts each text member and ignores non-text members', function () {
    emwinShMakeZip($this->root.'/kbox/MULTI.ZIP', ['A.TXT' => 'a', 'B.txt' => 'b', 'PIC.GIF' => 'gif-bytes']);

    emwinShRun($this, $this->root.'/kbox/MULTI.ZIP');

    expect(emwinShPosts($this))->toHaveCount(2);
});

test('images and other file types are ignored', function () {
    File::put($this->root.'/kbox/RADAR.GIF', 'gif');

    emwinShRun($this, $this->root.'/kbox/RADAR.GIF');

    expect(emwinShPosts($this))->toBe([]);
});

test('a corrupt zip is logged as an error, posts nothing, exits 0 and leaves no temp files', function () {
    File::put($this->root.'/kbox/BAD.ZIP', random_bytes(200));

    $result = emwinShRun($this, $this->root.'/kbox/BAD.ZIP');

    expect($result->exitCode())->toBe(0)
        ->and(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('BAD.ZIP')
        ->and(File::exists($this->root.'/delivered'))->toBeFalse();
});

test('an encrypted zip is skipped with an error', function () {
    emwinShMakeZip($this->root.'/kbox/SECRET.ZIP', ['S.TXT' => 'secret'], 'pw');

    $result = emwinShRun($this, $this->root.'/kbox/SECRET.ZIP');

    expect($result->exitCode())->toBe(0)->and(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('SECRET.ZIP');
});

test('an oversized or over-populated zip is refused before extraction', function () {
    emwinShMakeZip($this->root.'/kbox/BIG.ZIP', ['BIG.TXT' => str_repeat('x', 500)]);
    emwinShRun($this, $this->root.'/kbox/BIG.ZIP', ['EMWIN_MAX_UNZIP_BYTES' => '100']);

    emwinShMakeZip($this->root.'/kbox/MANY.ZIP', ['1.TXT' => 'a', '2.TXT' => 'b', '3.TXT' => 'c']);
    emwinShRun($this, $this->root.'/kbox/MANY.ZIP', ['EMWIN_MAX_UNZIP_MEMBERS' => '2']);

    expect(emwinShPosts($this))->toBe([])
        ->and(File::get($this->dir.'/logger.log'))->toContain('BIG.ZIP')->toContain('MANY.ZIP');
});

test('a zip with no text members logs an error and posts nothing', function () {
    emwinShMakeZip($this->root.'/kbox/NOTEXT.ZIP', ['PIC.GIF' => 'gif']);

    emwinShRun($this, $this->root.'/kbox/NOTEXT.ZIP');

    expect(emwinShPosts($this))->toBe([])->and(File::get($this->dir.'/logger.log'))->toContain('NOTEXT.ZIP');
});

test('a hostile member path cannot write outside the delivered directory', function () {
    emwinShMakeZip($this->root.'/kbox/EVIL.ZIP', ['../../evil.txt' => 'pwned']);

    emwinShRun($this, $this->root.'/kbox/EVIL.ZIP');

    expect(File::exists($this->dir.'/evil.txt'))->toBeFalse()
        ->and(File::exists($this->root.'/evil.txt'))->toBeFalse();
});

test('a failed POST is logged and does not crash or update the heartbeat', function () {
    File::put($this->root.'/kbox/RWRBOX.TXT', 'x');

    $result = emwinShRun($this, $this->root.'/kbox/RWRBOX.TXT', ['FAKE_CURL_FAIL' => '1']);

    expect($result->exitCode())->toBe(0)
        ->and(File::exists($this->dir.'/heartbeat'))->toBeFalse()
        ->and(File::get($this->dir.'/logger.log'))->toContain('RWRBOX');
});

test('a bad zip followed by a good one still delivers the good one', function () {
    File::put($this->root.'/kbox/BAD.ZIP', random_bytes(100));
    emwinShMakeZip($this->root.'/kbox/GOOD.ZIP', ['GOOD.TXT' => 'good']);

    emwinShRun($this, $this->root.'/kbox/BAD.ZIP');
    emwinShRun($this, $this->root.'/kbox/GOOD.ZIP');

    expect(emwinShPosts($this))->toHaveCount(1);
});

test('file names with unsafe characters are refused rather than put into JSON', function () {
    File::put($this->root.'/kbox/we"ird.TXT', 'x');

    emwinShRun($this, $this->root.'/kbox/we"ird.TXT');

    expect(emwinShPosts($this))->toBe([]);
});
```
Run: expect FAIL.

- [ ] **Step 2: Implement** `docker/emwin/pan-notify.sh`:
```sh
#!/bin/sh
# Per-file hook for npemwind's panfilter: pan-notify.sh <absolute path of the file just stored>.
# Unzips (or copies) each text product to a UNIQUE file under $ROOT/delivered/ and posts its
# relative path to the pipeline. Never fails npemwind: every problem is logged and skipped.
set -u

ROOT="${EMWIN_FILES_ROOT:-/var/npemwin/files/text}"
WEBHOOK_URL="${EMWIN_WEBHOOK_URL:-http://laravel.test/api/pipeline/emwin-products}"
MAX_BYTES="${EMWIN_MAX_UNZIP_BYTES:-5242880}"
MAX_MEMBERS="${EMWIN_MAX_UNZIP_MEMBERS:-50}"
HEARTBEAT="${EMWIN_HEARTBEAT_FILE:-/tmp/emwin-last-product}"

log() {
    level=$1
    shift
    if command -v logger >/dev/null 2>&1; then
        logger -t emwin-pan -p "user.$level" -- "$*"
    else
        echo "emwin-pan [$level] $*" >&2
    fi
}

[ $# -ge 1 ] || { log err "called without a file path"; exit 0; }
src=$1
[ -f "$src" ] || exit 0

# Delivers one already-placed file: $1 = absolute path under $ROOT.
deliver() {
    file=$1
    rel=${file#"$ROOT"/}
    case $rel in
        *[!A-Za-z0-9._/-]*) log err "refusing to deliver $rel: unsafe characters in the file name"; return 1 ;;
    esac
    if err=$(curl -sS -f --max-time 10 -X POST "$WEBHOOK_URL" \
        -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN:-}" \
        -H "Content-Type: application/json" \
        -d "{\"path\":\"$rel\"}" 2>&1); then
        : > "$HEARTBEAT"
        log info "delivered $rel"
        return 0
    fi
    log err "POST failed for $rel: $err"
    return 1
}

stamp=$(date -u +%Y%m%dT%H%M%S)
seq=0

# Places one text file ($1 = source file, $2 = original member/base name) under delivered/ with a unique name, then delivers it.
place_and_deliver() {
    from=$1
    name=$2
    seq=$((seq + 1))
    base=${name%.*}
    reldir=$(dirname "${src#"$ROOT"/}")
    [ "$reldir" = "." ] && reldir=""
    destdir="$ROOT/delivered${reldir:+/$reldir}"
    if ! mkdir -p "$destdir"; then
        log err "cannot create $destdir"
        return 1
    fi
    dest="$destdir/${base}_${stamp}_$$_${seq}.txt"
    if ! cp "$from" "$dest"; then
        log err "cannot copy $from to $dest"
        return 1
    fi
    chmod 644 "$dest"
    deliver "$dest"
}

handle_zip() {
    if ! listing=$(unzip -l "$src" 2>&1); then
        log err "cannot read zip $src: $listing"
        return 0
    fi
    summary=$(printf '%s\n' "$listing" | awk '
        $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9-]+$/ && $3 ~ /^[0-9]+:[0-9]+$/ { bytes += $1; n++ }
        END { print bytes + 0, n + 0 }')
    bytes=${summary% *}
    members=${summary#* }
    if [ "$members" -eq 0 ]; then
        log err "zip $src has no members"
        return 0
    fi
    if [ "$members" -gt "$MAX_MEMBERS" ] || [ "$bytes" -gt "$MAX_BYTES" ]; then
        log err "refusing zip $src: $members members / $bytes bytes exceeds the limits ($MAX_MEMBERS / $MAX_BYTES)"
        return 0
    fi

    tmp=$(mktemp -d "${TMPDIR:-/tmp}/emwin-unzip.XXXXXX") || { log err "cannot create a temp dir for $src"; return 0; }
    # -j drops directory components (no path traversal), -C matches *.txt case-insensitively.
    err=$(unzip -j -o -q -C "$src" '*.txt' -d "$tmp" 2>&1)
    rc=$?
    if [ "$rc" -gt 1 ]; then
        log err "unzip failed for $src (exit $rc): $err"
        rm -rf "$tmp"
        return 0
    fi

    found=0
    for extracted in "$tmp"/*; do
        [ -f "$extracted" ] || continue
        found=1
        place_and_deliver "$extracted" "$(basename "$extracted")" || true
    done
    [ "$found" -eq 1 ] || log err "zip $src contained no text members"
    rm -rf "$tmp"
}

case $src in
    *.[Zz][Ii][Pp]) handle_zip ;;
    *.[Tt][Xx][Tt]) place_and_deliver "$src" "$(basename "$src")" || true ;;
    *) : ;;
esac

exit 0
```
`chmod +x`. If the spike showed a different container filename convention, keep the case patterns but add whatever the spike found.

- [ ] **Step 3: Run tests** — PASS (`--filter=EmwinPanNotify`). If the "encrypted zip" test does not produce an error under the container's unzip version, keep the assertion that nothing is posted and that the error is logged — check `unzip -q -C SECRET.ZIP '*.txt'` exit code and adapt the `rc` threshold if needed, documenting why.
- [ ] **Step 4: Mutation-check** two tests (e.g. remove the `-j` flag; skip the size guard) and confirm they fail, then restore.
- [ ] **Step 5: Commit** `git add docker/emwin/pan-notify.sh tests/Feature/EmwinPanNotifyScriptTest.php && git commit -m "Add the EMWIN pan-notify hook with safe unzip and unique delivery copies"`.

---

### Task 4: Entrypoint, syslog, healthcheck, Dockerfile completion, Compose

**Files:** Create `docker/emwin/{entrypoint.sh,healthcheck.sh,rsyslog.conf}`; modify `docker/emwin/Dockerfile`, `compose.yaml`, `.env.example`, `config/services.php`.

- [ ] **Step 1: `docker/emwin/rsyslog.conf`:**
```
module(load="imuxsock")
*.* action(type="omfile" file="/dev/stdout")
```
- [ ] **Step 2: `docker/emwin/entrypoint.sh`** (adapt the daemon process pattern/user handling to the spike's findings):
```sh
#!/bin/sh
set -u

SERVERS_CONF=/usr/local/etc/npemwin/servers.conf
DELIVERED=/var/npemwin/files/text/delivered
RESTART_FLAG=/tmp/emwin-restart-requested
REFRESH_HOURS="${EMWIN_SERVERS_REFRESH_HOURS:-6}"
RETENTION_DAYS="${EMWIN_RETENTION_DAYS:-7}"

rsyslogd -n -f /etc/rsyslog-emwin.conf &
i=0
while [ ! -S /dev/log ] && [ "$i" -lt 50 ]; do sleep 0.1; i=$((i + 1)); done

/usr/local/bin/refresh-servers.sh || logger -t emwin -p user.warning "initial server refresh failed; using the existing list"

(
    while true; do
        sleep $((REFRESH_HOURS * 3600))
        /usr/local/bin/refresh-servers.sh
        if [ "$?" -eq 10 ]; then
            touch "$RESTART_FLAG"
            pkill -TERM -f /usr/local/sbin/npemwind
        fi
        find "$DELIVERED" -type f -mtime +"$RETENTION_DAYS" -delete 2>/dev/null
    done
) &

DAEMON_PID=""
trap '[ -n "$DAEMON_PID" ] && kill "$DAEMON_PID" 2>/dev/null; exit 0' TERM INT

while true; do
    runuser -u noaaport -- /usr/local/sbin/npemwind -F &
    DAEMON_PID=$!
    wait "$DAEMON_PID"
    status=$?
    if [ -f "$RESTART_FLAG" ]; then
        rm -f "$RESTART_FLAG"
        logger -t emwin -p user.info "restarting npemwind with the refreshed server list"
        continue
    fi
    exit "$status"
done
```
- [ ] **Step 3: `docker/emwin/healthcheck.sh`:**
```sh
#!/bin/sh
pgrep -f /usr/local/sbin/npemwind >/dev/null && curl -fsS -o /dev/null --max-time 3 http://localhost:8016/
```
- [ ] **Step 4: Finish the Dockerfile** — append:
```dockerfile
COPY docker/emwin/rsyslog.conf /etc/rsyslog-emwin.conf
COPY docker/emwin/entrypoint.sh docker/emwin/pan-notify.sh docker/emwin/refresh-servers.sh docker/emwin/healthcheck.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh && ln -s /usr/local/bin/pan-notify.sh /pan-notify.sh

EXPOSE 8016
HEALTHCHECK --interval=60s --timeout=5s --start-period=60s --retries=3 CMD ["/usr/local/bin/healthcheck.sh"]
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
```
(Adapt copy destinations so the panfilter path `/pan-notify.sh` from Task 1's `panfilter.rc` resolves; keep the symlink or change the rc — one or the other, consistently.)
- [ ] **Step 5: Compose** — add to `compose.yaml` after `glm-decoder`:
```yaml
    emwin:
        build:
            context: .
            dockerfile: docker/emwin/Dockerfile
        container_name: wxnotify-emwin
        environment:
            PIPELINE_WEBHOOK_TOKEN: '${PIPELINE_WEBHOOK_TOKEN}'
            EMWIN_WEBHOOK_URL: 'http://laravel.test/api/pipeline/emwin-products'
            EMWIN_SERVERS_REFRESH_HOURS: '${EMWIN_SERVERS_REFRESH_HOURS:-6}'
            EMWIN_MAX_UNZIP_BYTES: '${EMWIN_MAX_UNZIP_BYTES:-5242880}'
            EMWIN_RETENTION_DAYS: '${EMWIN_RETENTION_DAYS:-7}'
        ports:
            - '${EMWIN_WEB_PORT:-8016}:8016'
        volumes:
            - 'emwin-products:/var/npemwin/files/text'
        networks:
            - sail
        restart: unless-stopped
        depends_on:
            - laravel.test
```
In `laravel.test` `volumes:` add `- 'emwin-products:/emwin-products:ro'`; under top-level `volumes:` add `emwin-products:\n        driver: local`. In `.env.example` add `PIPELINE_EMWIN_PRODUCTS_PATH=/emwin-products` after `PIPELINE_PRODUCTS_PATH` and `EMWIN_WEB_PORT=8016`. In `config/services.php` `'pipeline'` array add `'emwin_products_path' => env('PIPELINE_EMWIN_PRODUCTS_PATH', '/emwin-products'),`.
- [ ] **Step 6: Validate and run.** `docker compose config -q` (expect no output = valid; needs `.env` present). Build: `docker compose build emwin`. Run just this service against a real community server WITHOUT touching the app stack's data: `docker compose up -d emwin` (it creates its own volume; `laravel.test` gets restarted only if you `up` it — do not) and verify over ~3 minutes: `docker logs wxnotify-emwin --tail 50` shows npemwind/syslog lines and "server list updated/unchanged"; `docker inspect --format '{{.State.Health.Status}}' wxnotify-emwin` reaches `healthy`; `curl -s localhost:8016 | head` answers; files appear under `docker exec wxnotify-emwin ls /var/npemwin/files/text`; `docker exec wxnotify-emwin ls /var/npemwin/files/text/delivered` shows unique delivered copies and `docker logs` shows "delivered …" lines OR "POST failed" lines (the endpoint doesn't exist until Task 6 — that is expected; confirm errors are logged and the container keeps running). Test the restart path: `docker exec wxnotify-emwin sh -c "printf '1.1.1.1\t2211\n' > /usr/local/etc/npemwin/servers.conf && /usr/local/bin/refresh-servers.sh; echo rc=\$?"` (expect rc=10), then simulate the loop's action: `docker exec wxnotify-emwin sh -c 'touch /tmp/emwin-restart-requested; pkill -TERM -f /usr/local/sbin/npemwind'` and confirm the log shows the restart and the container did not exit. `docker compose stop emwin` should return within ~10 s (signal handling works). Stop with `docker compose stop emwin` afterwards.
- [ ] **Step 7: Commit** `git add docker/emwin compose.yaml .env.example config/services.php && git commit -m "Add the emwin service: entrypoint, syslog-to-console, healthcheck and Compose wiring"`.

---

### Task 5: Migration, model, and WMO-header identity

**Files:** Create the migration, `app/Actions/Pipeline/ExtractWmoHeaderIdentity.php`, `tests/Feature/ExtractWmoHeaderIdentityTest.php`; modify `app/Models/IngestedProduct.php`.

- [ ] **Step 1: Failing tests** `tests/Feature/ExtractWmoHeaderIdentityTest.php`:
```php
<?php

use App\Actions\Pipeline\ExtractWmoHeaderIdentity;

test('it reads the WMO heading and AWIPS line from a warning', function () {
    $text = "WFUS53 KBOU 191622\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN";

    expect((new ExtractWmoHeaderIdentity)($text))->toBe([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'wmo_ddhhmm' => '191622', 'bbb' => null, 'awipsid' => 'torbou',
        'key' => 'wfus53 kbou 191622 torbou',
    ]);
});

test('control characters and carriage returns from the wire are ignored', function () {
    $text = "\x01\r\r\n123 \r\r\nWFUS53 KBOU 191622\r\r\nTORBOU\r\r\nBody";

    expect((new ExtractWmoHeaderIdentity)($text)['key'])->toBe('wfus53 kbou 191622 torbou');
});

test('a BBB amendment indicator is part of the key so corrections stay distinct', function () {
    $original = (new ExtractWmoHeaderIdentity)("WFUS53 KBOU 191622\nTORBOU\nx");
    $correction = (new ExtractWmoHeaderIdentity)("WFUS53 KBOU 191622 CCA\nTORBOU\nx");

    expect($correction['bbb'])->toBe('cca')
        ->and($correction['key'])->toBe('wfus53 kbou 191622 cca torbou')
        ->and($correction['key'])->not->toBe($original['key']);
});

test('an AWIPS line is optional', function () {
    $identity = (new ExtractWmoHeaderIdentity)("SAUS70 KWBC 191600\nMETAR KBOS 191554Z 00000KT\n");

    expect($identity['awipsid'])->toBeNull()->and($identity['key'])->toBe('saus70 kwbc 191600');
});

test('text without a WMO heading has no identity', function () {
    expect((new ExtractWmoHeaderIdentity)("TAF KORD 221432Z sample text"))->toBeNull()
        ->and((new ExtractWmoHeaderIdentity)(''))->toBeNull();
});

test('only the top of the product is searched', function () {
    $body = str_repeat("filler line\n", 30)."WFUS53 KBOU 191622\nTORBOU\n";

    expect((new ExtractWmoHeaderIdentity)($body))->toBeNull();
});
```
Run: FAIL.

- [ ] **Step 2: Implement**
```php
<?php

namespace App\Actions\Pipeline;

class ExtractWmoHeaderIdentity
{
    /**
     * Reads the WMO abbreviated heading ("TTAAII CCCC DDHHMM [BBB]") and the optional AWIPS
     * product-ID line from the top of a product, returning lowercased identity fields and a
     * normalized key shared by every feed carrying the same bulletin.
     *
     * @return ?array{cccc: string, ttaaii: string, wmo_ddhhmm: string, bbb: ?string, awipsid: ?string, key: string}
     */
    public function __invoke(string $text): ?array
    {
        $lines = [];
        foreach (array_slice(preg_split('/\r\n|\r|\n/', $text), 0, 12) as $line) {
            $line = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        foreach ($lines as $index => $line) {
            if (! preg_match('/^([A-Z]{4}\d{2})\s+([A-Z]{4})\s+(\d{6})(?:\s+([A-Z]{3}))?$/', $line, $m)) {
                continue;
            }

            $awips = null;
            if (isset($lines[$index + 1]) && preg_match('/^[A-Z0-9]{4,7}$/', $lines[$index + 1])) {
                $awips = strtolower($lines[$index + 1]);
            }

            $bbb = isset($m[4]) && $m[4] !== '' ? strtolower($m[4]) : null;
            $key = implode(' ', array_filter([strtolower($m[1]), strtolower($m[2]), $m[3], $bbb, $awips]));

            return [
                'cccc' => strtolower($m[2]),
                'ttaaii' => strtolower($m[1]),
                'wmo_ddhhmm' => $m[3],
                'bbb' => $bbb,
                'awipsid' => $awips,
                'key' => $key,
            ];
        }

        return null;
    }
}
```
The test "only the top is searched" relies on the 12-line window; the "\x01\r\r\n123 \r\r\n" test keeps the heading inside the window because empty lines are dropped after slicing — if that test fails because slicing precedes empty-line removal (12 raw lines), slice AFTER filtering (`array_slice($lines, 0, 8)`) and re-check the "top only" test still fails as intended.

- [ ] **Step 3: Migration** `database/migrations/2026_09_19_100001_add_source_and_wmo_header_key_to_ingested_products_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->string('source')->default('nwws')->after('file_path');
            $table->string('wmo_header_key')->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->dropIndex(['wmo_header_key']);
            $table->dropColumn(['source', 'wmo_header_key']);
        });
    }
};
```
Add `'source', 'wmo_header_key'` to `IngestedProduct`'s `#[Fillable([...])]`.
- [ ] **Step 4: Run** the new tests plus `IngestedProductResourceTest`, `IngestProductTextTest`, `TestProductGeneratorEndToEndTest` — PASS. Commit: `git add app database tests && git commit -m "Add WMO-header identity extraction and the source/header-key columns"`.

---

### Task 6: Heartbeat, cross-feed dedup, the EMWIN endpoint, and the NWWS update

**Files:** Create `app/Support/Pipeline/PipelineHeartbeat.php`, `app/Actions/Pipeline/IngestOncePerWmoHeader.php`, `app/Http/Controllers/IngestEmwinProductController.php`, `tests/Feature/IngestEmwinProductControllerTest.php`; modify `routes/api.php`, `app/Http/Controllers/IngestNwwsProductController.php`, `tests/Feature/IngestNwwsProductControllerTest.php`.

- [ ] **Step 1: Failing tests** `tests/Feature/IngestEmwinProductControllerTest.php`:
```php
<?php

use App\Models\IngestedProduct;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

function emwinApiWarning(string $extra = ''): string
{
    return "WFUS53 KBOU 191622{$extra}\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN\nTornado Warning";
}

function emwinApiPost(object $t, string $path)
{
    return $t->postJson('/api/pipeline/emwin-products', ['path' => $path], ['Authorization' => 'Bearer test-webhook-token']);
}

beforeEach(function () {
    Config::set('services.pipeline.webhook_token', 'test-webhook-token');
    $this->emwinRoot = sys_get_temp_dir().'/emwin-products-test-'.uniqid();
    File::makeDirectory($this->emwinRoot.'/delivered/kbou', recursive: true);
    Config::set('services.pipeline.emwin_products_path', $this->emwinRoot);
});

afterEach(fn () => File::deleteDirectory($this->emwinRoot));

test('it requires the pipeline bearer token', function () {
    $this->postJson('/api/pipeline/emwin-products', ['path' => 'x'])->assertUnauthorized();
    $this->postJson('/api/pipeline/emwin-products', ['path' => 'x'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

test('it ingests a product with identity from the WMO header, source emwin and a prefixed file path', function () {
    File::put($this->emwinRoot.'/delivered/kbou/TORBOU_1.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/TORBOU_1.txt')->assertNoContent();

    $product = IngestedProduct::sole();
    expect($product->source)->toBe('emwin')
        ->and($product->file_path)->toBe('emwin/delivered/kbou/TORBOU_1.txt')
        ->and($product->cccc)->toBe('kbou')->and($product->ttaaii)->toBe('wfus53')
        ->and($product->awipsid)->toBe('torbou')->and($product->wmo_ddhhmm)->toBe('191622')
        ->and($product->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and($product->vtec_event_number)->toBe('0009');
});

test('a product with no parseable header is still ingested, without a key', function () {
    File::put($this->emwinRoot.'/delivered/kbou/odd.txt', 'no header here');

    emwinApiPost($this, 'delivered/kbou/odd.txt')->assertNoContent();

    expect(IngestedProduct::sole()->wmo_header_key)->toBeNull();
});

test('paths that escape the root or do not exist return 404', function () {
    File::put(dirname($this->emwinRoot).'/outside-'.basename($this->emwinRoot).'.txt', 'x');

    emwinApiPost($this, 'missing.txt')->assertNotFound();
    emwinApiPost($this, '../outside-'.basename($this->emwinRoot).'.txt')->assertNotFound();
    expect(IngestedProduct::count())->toBe(0);

    File::delete(dirname($this->emwinRoot).'/outside-'.basename($this->emwinRoot).'.txt');
});

test('a product NWWS already ingested is not ingested again from EMWIN', function () {
    IngestedProduct::factory()->create(['file_path' => 'ktwc/nwws.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou', 'source' => 'nwws']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a differing amendment indicator or time is not a duplicate', function () {
    IngestedProduct::factory()->create(['file_path' => 'a/b.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/cor.txt', emwinApiWarning(' CCA'));

    emwinApiPost($this, 'delivered/kbou/cor.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(2);
});

test('every accepted post records the emwin heartbeat, including deduplicated ones', function () {
    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->toBeNull();
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt')->assertNoContent();

    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->not->toBeNull();
});
```
Append to `IngestNwwsProductControllerTest.php`:
```php
test('an NWWS product populates the header key and is skipped when EMWIN already ingested it', function () {
    $text = "WFUS53 KBOU 191622\nTORBOU\nBody";
    IngestedProduct::factory()->create(['file_path' => 'emwin/delivered/kbou/x.txt', 'source' => 'emwin', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::makeDirectory($this->productsRoot.'/kbou', recursive: true, force: true);
    File::put($this->productsRoot.'/kbou/kbou_wfus53-torbou.191622_1.txt', $text);

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kbou/kbou_wfus53-torbou.191622_1.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a first-seen NWWS product stores source nwws and its header key and records the nwws heartbeat', function () {
    File::makeDirectory($this->productsRoot.'/kbou', recursive: true, force: true);
    File::put($this->productsRoot.'/kbou/kbou_wfus53-torbou.191622_1.txt', "WFUS53 KBOU 191622\nTORBOU\nBody");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kbou/kbou_wfus53-torbou.191622_1.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    expect(IngestedProduct::sole()->source)->toBe('nwws')
        ->and(IngestedProduct::sole()->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and(App\Support\Pipeline\PipelineHeartbeat::lastReceivedAt('nwws'))->not->toBeNull();
});
```
Run: FAIL.

- [ ] **Step 2: `PipelineHeartbeat`**
```php
<?php

namespace App\Support\Pipeline;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PipelineHeartbeat
{
    public static function record(string $feed): void
    {
        Cache::forever("pipeline.heartbeat.{$feed}", now()->toIso8601String());
    }

    public static function lastReceivedAt(string $feed): ?Carbon
    {
        $value = Cache::get("pipeline.heartbeat.{$feed}");

        return $value === null ? null : Carbon::parse($value);
    }
}
```
- [ ] **Step 3: `IngestOncePerWmoHeader`**
```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class IngestOncePerWmoHeader
{
    /**
     * Runs $ingest unless a product with the same header key was already ingested (by either feed).
     * A short lock closes the race where both feeds deliver the same bulletin at once.
     * Returns true when $ingest ran, false when the product was a duplicate.
     */
    public function __invoke(?string $key, Closure $ingest): bool
    {
        if ($key === null) {
            $ingest();

            return true;
        }

        try {
            return Cache::lock("wmo-header:{$key}", 10)->block(5, function () use ($key, $ingest): bool {
                if (IngestedProduct::where('wmo_header_key', $key)->exists()) {
                    return false;
                }

                $ingest();

                return true;
            });
        } catch (LockTimeoutException) {
            abort(503, 'Another feed is ingesting the same product; retry.');
        }
    }
}
```
- [ ] **Step 4: `IngestEmwinProductController`** (mirrors the NWWS controller; place beside it):
```php
<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\ExtractWmoHeaderIdentity;
use App\Actions\Pipeline\IngestOncePerWmoHeader;
use App\Actions\Pipeline\IngestProductText;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IngestEmwinProductController extends Controller
{
    public function __construct(
        private readonly IngestProductText $ingestProductText,
        private readonly ExtractWmoHeaderIdentity $extractWmoHeaderIdentity,
        private readonly IngestOncePerWmoHeader $ingestOncePerWmoHeader,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['path' => ['required', 'string']]);

        PipelineHeartbeat::record('emwin');

        $root = realpath((string) config('services.pipeline.emwin_products_path'));
        $absolutePath = $root !== false ? realpath($root.DIRECTORY_SEPARATOR.$validated['path']) : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $text = file_get_contents($absolutePath);
        $identity = ($this->extractWmoHeaderIdentity)($text);

        ($this->ingestOncePerWmoHeader)($identity['key'] ?? null, fn () => ($this->ingestProductText)(
            $text,
            'emwin/'.$validated['path'],
            [
                'cccc' => $identity['cccc'] ?? null,
                'ttaaii' => $identity['ttaaii'] ?? null,
                'awipsid' => $identity['awipsid'] ?? null,
                'wmo_ddhhmm' => $identity['wmo_ddhhmm'] ?? null,
                'nwws_id' => null,
            ],
            false,
            ['source' => 'emwin', 'wmo_header_key' => $identity['key'] ?? null],
        ));

        return response()->noContent();
    }
}
```
Route (`routes/api.php`): `Route::post('/pipeline/emwin-products', IngestEmwinProductController::class)->middleware('pipeline.webhook');` with the import.
- [ ] **Step 5: Update `IngestNwwsProductController`** — inject `ExtractWmoHeaderIdentity` and `IngestOncePerWmoHeader`; after validation call `PipelineHeartbeat::record('nwws')`; after reading `$text`, compute `$key = $isCap ? null : (($this->extractWmoHeaderIdentity)($text)['key'] ?? null)`; replace the final direct call with:
```php
($this->ingestOncePerWmoHeader)($key, fn () => ($this->ingestProductText)($text, $validated['path'], $parsed, $isCap, ['source' => 'nwws', 'wmo_header_key' => $key]));
```
Keep the unparseable-filename warning as is.
- [ ] **Step 6: Run** `--filter='IngestEmwin|IngestNwws|ExtractWmoHeader|IngestProductText|CapIngestion'` — PASS (existing NWWS tests must still pass unchanged). Mutation-check: remove the `exists()` check (dedup tests must fail); remove `PipelineHeartbeat::record('emwin')` (heartbeat test must fail).
- [ ] **Step 7: Commit** `git add app routes tests && git commit -m "Add the EMWIN ingestion endpoint with cross-feed dedup and feed heartbeats"`.

---

### Task 7: Pipeline Control — EMWIN Byteblaster row

**Files:** Modify `app/Filament/Admin/Pages/PipelineControl.php`, `resources/views/filament/admin/pages/pipeline-control.blade.php`, `tests/Feature/PipelineControlTest.php`.

The existing NWWS actions (`start`, `stop`, `restart`) and their tests must keep working unchanged. Add parallel EMWIN actions rather than renaming.

- [ ] **Step 1: Update/add tests** in `tests/Feature/PipelineControlTest.php`. Replace the last test ("the emwin rows remain static placeholders") with:
```php
test('the emwin http backup row remains a static placeholder', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)->assertSee('EMWIN HTTP backup')->assertSee('Status unknown');
});

test('the emwin byteblaster row shows its docker status and start is hidden while it runs', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSee('EMWIN Byteblaster')
        ->assertSee('Running')
        ->assertActionHidden('startEmwin')
        ->assertActionVisible('stopEmwin');
});

test('the emwin stop, start and restart actions call the emwin container and notify', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/stop' => Http::response('', 204),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/restart' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('stopEmwin')->assertNotified('EMWIN stop succeeded')
        ->callAction('restartEmwin')->assertNotified('EMWIN restart succeeded');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/containers/wxnotify-emwin/stop'));
});

test('a failed emwin docker call notifies a failure instead of erroring', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/stop' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)->callAction('stopEmwin')->assertNotified('Failed to stop EMWIN');
});

test('the emwin row shows the last product received from the heartbeat', function () {
    Http::fake(['docker-socket-proxy:2375/*' => Http::response(['State' => ['Status' => 'running']], 200)]);
    App\Support\Pipeline\PipelineHeartbeat::record('emwin');

    livewire(PipelineControl::class)->assertSee('Last product');
});

test('a missing emwin container degrades to Not found without breaking the nwws row', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response([], 404),
    ]);

    livewire(PipelineControl::class)->assertSuccessful()->assertSee('Not found');
});
```
Also update every existing test's `Http::fake` in this file only if it now fails because the EMWIN container status request is unfaked (unfaked requests hit the network) — add a default `'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin/json' => Http::response(['State' => ['Status' => 'running']], 200)` entry to each existing `Http::fake([...])` (or add a `beforeEach` that sets it via `Http::preventStrayRequests()` + a shared fake; keep each test's own NWWS assertions intact).
- [ ] **Step 2: Implement.** In `PipelineControl.php` generalize without renaming existing public methods: add `private const EMWIN_CONTAINER = 'wxnotify-emwin';`, a `private array $statusCache = [];` map plus `private function statusOf(string $container): string` (the existing `resolveNwwsStatus` logic keyed by container; keep `nwwsStatus()` etc. delegating to it), `emwinStatus()`, `emwinStatusColor()`, `emwinStatusLabel()`, `emwinLastProductAt(): ?string` (uses `PipelineHeartbeat::lastReceivedAt('emwin')?->diffForHumans()`), and actions `startEmwinAction()`, `stopEmwinAction()`, `restartEmwinAction()` copying the NWWS actions with names `startEmwin`/`stopEmwin`/`restartEmwin`, container `EMWIN_CONTAINER`, confirmation copy naming the EMWIN Byteblaster feed, and `runDockerAction` generalized to take a feed label (`'NWWS'`/`'EMWIN'`) and reset the right cache entry (titles `"{$label} {$verb} succeeded"` / `"Failed to {$verb} {$label}"`). In the Blade view, replace the static "EMWIN Byteblaster" loop entry with a real row mirroring the NWWS row (status badge, `{{ $this->startEmwinAction }}` etc. guarded by `isVisible()`, and a small "Last product: …" line showing `$this->emwinLastProductAt() ?? 'never'`), keeping only "EMWIN HTTP backup" in the static loop.
- [ ] **Step 3: Run** `tests/Feature/PipelineControlTest.php` — all PASS. Mutation-check: make `stopEmwinAction` call the NWWS container (an emwin test must fail).
- [ ] **Step 4: Commit** `git add app resources tests && git commit -m "Wire the EMWIN Byteblaster container into Pipeline Control"`.

---

### Task 8: Rules note, full verification, and end-to-end run

- [ ] **Step 1:** Record a rule with the Boost `record-rule` tool: glob `docker/emwin/**`, title "EMWIN container invariants", note: "The emwin image picks its npemwin .deb from TARGETARCH out of docker/emwin/packages (verified against SHA256SUMS); bump amd64 and arm64 together. pan-notify.sh must always exit 0, deliver only unique copies under delivered/ (Laravel keys ingested rows by file_path, and EMWIN file names repeat), and refuse unsafe file names. refresh-servers.sh must never overwrite servers.conf with fewer than 3 servers. Cross-feed dedup keys on ingested_products.wmo_header_key (ExtractWmoHeaderIdentity) — both feed controllers must go through IngestOncePerWmoHeader." Commit `.ai`.
- [ ] **Step 2:** Full suite in the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"` — all pass. `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 3: End-to-end (dev stack).** Apply the new migration to the DEV database only via the normal, non-destructive path: `docker exec wxnotify-nextgen-laravel.test-1 php artisan migrate --no-interaction` (NEVER `migrate:fresh`). Restart only what changed: `docker compose up -d --build emwin laravel.test`. Confirm the token is set in `.env` (`PIPELINE_WEBHOOK_TOKEN`). Watch `docker logs -f wxnotify-emwin` for "delivered …" lines, then verify in the app: `docker exec wxnotify-nextgen-laravel.test-1 php artisan tinker --execute 'echo App\Models\IngestedProduct::where("source","emwin")->count();'` is greater than 0 and a sample has a `wmo_header_key`; the Pipeline Control page (admin panel) shows EMWIN Byteblaster Running with a recent "Last product"; and a product that arrives on both feeds is ingested once (compare `wmo_header_key` counts: `select wmo_header_key, count(*) from ingested_products where wmo_header_key is not null group by 1 having count(*) > 1;` must return no rows). Report what you saw; do not paste secrets.
- [ ] **Step 5:** Commit any fixes; report.

---

## Self-Review

**Spec coverage:** multi-arch image from `TARGETARCH`, vendored debs + checksum verification, npemwin 3.0.1p1 on both (Task 1) · site config from the user's samples, panfilter → hook (Tasks 1, 3) · `pan-notify.sh` with unzip, guards, unique delivered copies, error-and-move-on, heartbeat file (Task 3) · `refresh-servers.sh` with safety rules and exit codes (Task 2) · entrypoint with rsyslog→stdout, restart-on-change loop, retention pruning, healthcheck, port 8016 mapping (Task 4) · Compose service `emwin`, volumes, env, read-only mount (Task 4) · endpoint, WMO-header identity, `source` + `wmo_header_key`, cross-feed dedup with lock, heartbeat (Tasks 5–6) · NWWS controller populates key and dedups (Task 6) · Pipeline Control EMWIN row + last product (Task 7) · verification spike recorded (Task 1) · testing per spec (Tasks 2, 3, 5–7 tests; container build/run in Tasks 1, 4, 8) · out-of-scope items untouched.

**Placeholder scan:** the only intentional adaptation points are flagged with what to check (spike findings that may change process names or file-name patterns; Pipeline Control's implementation prose gives exact method/action names and behaviors but leaves the mechanical copy of the NWWS methods to the implementer — the tests pin the names and behavior).

**Type consistency:** identity array keys (`cccc, ttaaii, wmo_ddhhmm, bbb, awipsid, key`) match between `ExtractWmoHeaderIdentity`, both controllers and tests; `PipelineHeartbeat::record/lastReceivedAt` used identically in Tasks 6–7; action names `startEmwin/stopEmwin/restartEmwin` match tests; exit codes 0/10/1 consistent between the script, its tests and the entrypoint; `delivered/` path prefix consistent across pan-notify, tests and the endpoint tests.

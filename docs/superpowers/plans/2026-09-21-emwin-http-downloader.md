# EMWIN HTTP Product Downloader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Go client in a Docker container that polls NOAA's public EMWIN HTTP text batch (and optionally the image batch) at its own cadence, unzips new products safely, and sends them to the alert pipeline's EMWIN endpoint where the existing cross-feed dedup prevents duplicate alerts; startable and stoppable from Pipeline Control.

**Architecture:** A self-contained Go module in `docker/emwin-http/` (config, cadence, conditional-GET fetcher, safe unzip, persisted seen-set state, poster, cycle/loop runner, healthcheck, main). One container (`wxnotify-emwin-http`) shares the `emwin-products` volume with Laravel, which reads posted paths exactly as it does for the Byteblaster feed. The Laravel ingest endpoint learns an optional `feed` value so the backup gets its own heartbeat, and Pipeline Control gets a real EMWIN HTTP row.

**Tech Stack:** Go 1.27 (stdlib only: `net/http`, `archive/zip`, `encoding/json`, `log/slog`), Docker (multi-stage, static binary), Laravel 13 + Filament v5 + Pest for the Laravel side.

**Spec:** `docs/superpowers/specs/2026-09-21-emwin-http-downloader-design.md`

## Global Constraints

- Go standard library only: no third-party Go modules (`go.mod` has no `require`).
- Run the Go tests on the host: `cd /Users/jimmy/Documents/WxNotify/wxnotify-nextgen/docker/emwin-http && go test ./...` (Go 1.27 is installed). No test may touch the network; use `net/http/httptest` and temp dirs.
- Laravel tests run only inside Sail: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"` (the full suite takes about two minutes and is moved to the background; wait for it). Run `vendor/bin/pint --dirty --format agent` on the host before each commit that touches PHP.
- Leave `CLAUDE.md` unchanged (`git checkout CLAUDE.md` if a hook modified it). No new Composer dependencies. NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed`; do not run `php artisan migrate` on the dev database. Do NOT start the new container against the real NOAA server.
- Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- Before editing PHP, read `.ai/rules/index.md` and the matching rule files (`.ai/rules/emwin.md` applies to `docker/emwin/**`; read it for the EMWIN container invariants, especially: Laravel keys ingested rows by `file_path`, so never post a path whose content can be overwritten by a later issuance).
- Config keys (exact): `server_host`, `server_path`, `storage_dir`, `download_text`, `download_images`, `text_zip`, `image_zip`. Defaults: `tgftp.nws.noaa.gov`, `/SL.us008001/CU.EMWIN/DF.xt/DC.gsatR/OPS/`, `/emwin-products/http`, `true`, `false`, `txtmin02.zip`, `imgmin15.zip`.
- Cadence from the zip name: `txtmin02` → 2 min, `txtmin06` → 6, `txtmin20` → 20, `imgmin15` → 15, `*hrs01` → 60, `*hrs03` → 180; an unrecognized name is a config error.
- Container name `wxnotify-emwin-http`; the client POSTs `{"path": "http/<name>", "feed": "http"}` to `EMWIN_WEBHOOK_URL` with `Authorization: Bearer $PIPELINE_WEBHOOK_TOKEN`. Products stay `source = emwin`.
- Unzip limits (env-overridable): 5 MiB per member, 100 MiB total uncompressed, 20,000 members per zip, 20 MiB download cap. A breaching zip is discarded and logged, never partially processed.
- The seen-set keeps the most recent 50,000 names. Retention: files older than `EMWIN_RETENTION_DAYS` (default 7) are deleted.
- Design ruling (deviation from the spec's wording): the per-zip state files live in `EMWIN_HTTP_STATE_DIR` (default `/state`, its own named volume `emwin-http-state`) instead of `storage_dir`'s parent, so state is never on the volume Laravel reads. Behavior is otherwise as specified.

---

### Task 1: Go module, config, cadence and env settings

**Files (all under `docker/emwin-http/`):**
- Create: `go.mod`
- Create: `internal/config/config.go`, `internal/config/config_test.go`
- Create: `internal/cadence/cadence.go`, `internal/cadence/cadence_test.go`

**Interfaces:**
- Produces (later tasks rely on these exact names):
  - `config.Config` struct with JSON tags matching the config keys; `config.Defaults() Config`; `config.Load(path string) (Config, error)`; `(Config).Validate() error`; `(Config).URLFor(zip string) string`; `(Config).ImagesDir() string`.
  - `config.Env` struct and `config.FromEnv(getenv func(string) string) (Env, error)` with fields `WebhookURL, WebhookToken, StateDir string; RetentionDays int; MaxZipBytes, MaxMemberBytes, MaxTotalBytes int64; MaxMembers int`.
  - `cadence.ForZip(name string) (time.Duration, error)`.

- [ ] **Step 1: Create the module**

`docker/emwin-http/go.mod`:

```
module wxnotify.local/emwin-http

go 1.27
```

- [ ] **Step 2: Write the failing tests**

`internal/cadence/cadence_test.go`:

```go
package cadence

import (
	"testing"
	"time"
)

func TestForZip(t *testing.T) {
	cases := map[string]time.Duration{
		"txtmin02.zip": 2 * time.Minute,
		"txtmin06.zip": 6 * time.Minute,
		"txtmin20.zip": 20 * time.Minute,
		"imgmin15.zip": 15 * time.Minute,
		"txthrs01.zip": time.Hour,
		"txthrs03.zip": 3 * time.Hour,
		"imghrs01.zip": time.Hour,
		"imghrs03.zip": 3 * time.Hour,
	}
	for name, want := range cases {
		got, err := ForZip(name)
		if err != nil || got != want {
			t.Errorf("ForZip(%q) = %v, %v; want %v", name, got, err, want)
		}
	}
}

func TestForZipRejectsUnknownNames(t *testing.T) {
	for _, name := range []string{"", "foo.zip", "txtmin00.zip", "txtmin2.zip", "txtmin02.tar", "../txtmin02.zip", "TXTMIN02.zip"} {
		if _, err := ForZip(name); err == nil {
			t.Errorf("ForZip(%q) should fail", name)
		}
	}
}
```

`internal/config/config_test.go`:

```go
package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeConfig(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "config.json")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestLoadAppliesDefaults(t *testing.T) {
	cfg, err := Load(writeConfig(t, `{}`))
	if err != nil {
		t.Fatal(err)
	}
	want := Defaults()
	if cfg != want {
		t.Fatalf("got %+v, want defaults %+v", cfg, want)
	}
	if !cfg.DownloadText || cfg.DownloadImages {
		t.Fatalf("flags should default to text on, images off: %+v", cfg)
	}
}

func TestLoadOverridesAndExplicitFalse(t *testing.T) {
	cfg, err := Load(writeConfig(t, `{"server_host":"example.test","download_text":false,"download_images":true,"text_zip":"txtmin06.zip"}`))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ServerHost != "example.test" || cfg.DownloadText || !cfg.DownloadImages || cfg.TextZip != "txtmin06.zip" {
		t.Fatalf("overrides not applied: %+v", cfg)
	}
}

func TestLoadRejectsBadConfigs(t *testing.T) {
	bad := map[string]string{
		"unknown key":      `{"nope": 1}`,
		"not json":         `{`,
		"host with scheme": `{"server_host":"https://example.test"}`,
		"host with path":   `{"server_host":"example.test/x"}`,
		"host empty":       `{"server_host":""}`,
		"path traversal":   `{"server_path":"/a/../b/"}`,
		"path no slash":    `{"server_path":"OPS/"}`,
		"relative storage": `{"storage_dir":"products/http"}`,
		"bad text zip":     `{"text_zip":"../txtmin02.zip"}`,
		"unknown cadence":  `{"text_zip":"foo.zip"}`,
		"bad image zip":    `{"image_zip":"IMG.zip"}`,
	}
	for name, body := range bad {
		if _, err := Load(writeConfig(t, body)); err == nil {
			t.Errorf("%s: expected an error", name)
		}
	}
}

func TestLoadMissingFile(t *testing.T) {
	if _, err := Load(filepath.Join(t.TempDir(), "absent.json")); err == nil {
		t.Fatal("expected an error for a missing file")
	}
}

func TestPathIsNormalisedWithTrailingSlash(t *testing.T) {
	cfg, err := Load(writeConfig(t, `{"server_path":"/a/b"}`))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ServerPath != "/a/b/" {
		t.Fatalf("ServerPath = %q", cfg.ServerPath)
	}
}

func TestURLForAndImagesDir(t *testing.T) {
	cfg := Defaults()
	if got := cfg.URLFor("txtmin02.zip"); got != "https://tgftp.nws.noaa.gov/SL.us008001/CU.EMWIN/DF.xt/DC.gsatR/OPS/txtmin02.zip" {
		t.Fatalf("URLFor = %q", got)
	}
	if got := cfg.ImagesDir(); got != "/emwin-products/http-images" {
		t.Fatalf("ImagesDir = %q", got)
	}
}

func TestFromEnvDefaultsAndOverrides(t *testing.T) {
	env, err := FromEnv(func(k string) string {
		return map[string]string{
			"EMWIN_WEBHOOK_URL":       "http://laravel.test/api/pipeline/emwin-products",
			"PIPELINE_WEBHOOK_TOKEN":  "t",
			"EMWIN_RETENTION_DAYS":    "3",
			"EMWIN_HTTP_MAX_MEMBERS":  "10",
		}[k]
	})
	if err != nil {
		t.Fatal(err)
	}
	if env.StateDir != "/state" || env.RetentionDays != 3 || env.MaxMembers != 10 ||
		env.MaxMemberBytes != 5<<20 || env.MaxTotalBytes != 100<<20 || env.MaxZipBytes != 20<<20 {
		t.Fatalf("unexpected env: %+v", env)
	}
}

func TestFromEnvRejectsNonNumbers(t *testing.T) {
	_, err := FromEnv(func(k string) string {
		if k == "EMWIN_RETENTION_DAYS" {
			return "soon"
		}
		return ""
	})
	if err == nil || !strings.Contains(err.Error(), "EMWIN_RETENTION_DAYS") {
		t.Fatalf("expected an error naming the variable, got %v", err)
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd docker/emwin-http && go test ./...`
Expected: FAIL (packages have no implementation).

- [ ] **Step 4: Write the implementation**

`internal/cadence/cadence.go`:

```go
// Package cadence derives a zip file's update interval from its name.
package cadence

import (
	"fmt"
	"regexp"
	"strconv"
	"time"
)

var pattern = regexp.MustCompile(`^(?:txt|img)(min|hrs)(\d{2})\.zip$`)

// ForZip returns the update cadence encoded in an EMWIN zip file name:
// txtmin02.zip is every 2 minutes, imgmin15.zip every 15, txthrs03.zip every 3 hours.
func ForZip(name string) (time.Duration, error) {
	m := pattern.FindStringSubmatch(name)
	if m == nil {
		return 0, fmt.Errorf("unrecognised EMWIN zip file name %q", name)
	}

	n, _ := strconv.Atoi(m[2])
	if n == 0 {
		return 0, fmt.Errorf("zip file name %q has a zero cadence", name)
	}

	unit := time.Minute
	if m[1] == "hrs" {
		unit = time.Hour
	}

	return time.Duration(n) * unit, nil
}
```

`internal/config/config.go`:

```go
// Package config loads and validates the JSON config file and the environment settings.
package config

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"wxnotify.local/emwin-http/internal/cadence"
)

type Config struct {
	ServerHost     string `json:"server_host"`
	ServerPath     string `json:"server_path"`
	StorageDir     string `json:"storage_dir"`
	DownloadText   bool   `json:"download_text"`
	DownloadImages bool   `json:"download_images"`
	TextZip        string `json:"text_zip"`
	ImageZip       string `json:"image_zip"`
}

func Defaults() Config {
	return Config{
		ServerHost:     "tgftp.nws.noaa.gov",
		ServerPath:     "/SL.us008001/CU.EMWIN/DF.xt/DC.gsatR/OPS/",
		StorageDir:     "/emwin-products/http",
		DownloadText:   true,
		DownloadImages: false,
		TextZip:        "txtmin02.zip",
		ImageZip:       "imgmin15.zip",
	}
}

var (
	hostPattern = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$`)
	pathPattern = regexp.MustCompile(`^/[A-Za-z0-9._/-]*$`)
	zipPattern  = regexp.MustCompile(`^[a-z0-9]+\.zip$`)
)

// Load reads the JSON file over the defaults and validates the result.
func Load(file string) (Config, error) {
	data, err := os.ReadFile(file)
	if err != nil {
		return Config{}, fmt.Errorf("read config: %w", err)
	}

	cfg := Defaults()
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&cfg); err != nil {
		return Config{}, fmt.Errorf("parse config: %w", err)
	}

	if !strings.HasSuffix(cfg.ServerPath, "/") {
		cfg.ServerPath += "/"
	}

	if err := cfg.Validate(); err != nil {
		return Config{}, err
	}

	return cfg, nil
}

func (c Config) Validate() error {
	if !hostPattern.MatchString(c.ServerHost) {
		return fmt.Errorf("server_host %q must be a bare hostname", c.ServerHost)
	}
	if !pathPattern.MatchString(c.ServerPath) || strings.Contains(c.ServerPath, "..") {
		return fmt.Errorf("server_path %q must be an absolute path without '..'", c.ServerPath)
	}
	if !filepath.IsAbs(c.StorageDir) {
		return fmt.Errorf("storage_dir %q must be an absolute path", c.StorageDir)
	}
	for field, zip := range map[string]string{"text_zip": c.TextZip, "image_zip": c.ImageZip} {
		if !zipPattern.MatchString(zip) {
			return fmt.Errorf("%s %q must look like txtmin02.zip", field, zip)
		}
		if _, err := cadence.ForZip(zip); err != nil {
			return fmt.Errorf("%s: %w", field, err)
		}
	}
	return nil
}

// URLFor is the download URL of a zip file on the configured server.
func (c Config) URLFor(zip string) string {
	return "https://" + c.ServerHost + c.ServerPath + zip
}

// ImagesDir is where image zips are unzipped, beside storage_dir.
func (c Config) ImagesDir() string {
	return path.Join(path.Dir(c.StorageDir), "http-images")
}

// Env holds the settings that come from the environment, not the config file.
type Env struct {
	WebhookURL     string
	WebhookToken   string
	StateDir       string
	RetentionDays  int
	MaxZipBytes    int64
	MaxMemberBytes int64
	MaxTotalBytes  int64
	MaxMembers     int
}

func FromEnv(getenv func(string) string) (Env, error) {
	env := Env{
		WebhookURL:   getenv("EMWIN_WEBHOOK_URL"),
		WebhookToken: getenv("PIPELINE_WEBHOOK_TOKEN"),
		StateDir:     "/state",
	}
	if v := getenv("EMWIN_HTTP_STATE_DIR"); v != "" {
		env.StateDir = v
	}

	var err error
	if env.RetentionDays, err = intVar(getenv, "EMWIN_RETENTION_DAYS", 7); err != nil {
		return Env{}, err
	}
	if env.MaxMembers, err = intVar(getenv, "EMWIN_HTTP_MAX_MEMBERS", 20000); err != nil {
		return Env{}, err
	}
	for name, target := range map[string]*int64{
		"EMWIN_HTTP_MAX_ZIP_BYTES":    &env.MaxZipBytes,
		"EMWIN_HTTP_MAX_MEMBER_BYTES": &env.MaxMemberBytes,
		"EMWIN_HTTP_MAX_TOTAL_BYTES":  &env.MaxTotalBytes,
	} {
		def := map[string]int64{
			"EMWIN_HTTP_MAX_ZIP_BYTES":    20 << 20,
			"EMWIN_HTTP_MAX_MEMBER_BYTES": 5 << 20,
			"EMWIN_HTTP_MAX_TOTAL_BYTES":  100 << 20,
		}[name]
		n, err := intVar(getenv, name, int(def))
		if err != nil {
			return Env{}, err
		}
		*target = int64(n)
	}

	return env, nil
}

func intVar(getenv func(string) string, name string, def int) (int, error) {
	raw := getenv(name)
	if raw == "" {
		return def, nil
	}
	n, err := strconv.Atoi(raw)
	if err != nil || n < 0 {
		return 0, fmt.Errorf("%s must be a non-negative integer, got %q", name, raw)
	}
	return n, nil
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd docker/emwin-http && gofmt -l . && go vet ./... && go test ./...`
Expected: PASS, and `gofmt -l .` prints nothing (run `gofmt -w .` if it does).

- [ ] **Step 6: Commit**

```bash
git add docker/emwin-http/go.mod docker/emwin-http/internal
git commit -m "$(cat <<'EOF'
Add the EMWIN HTTP client's config, cadence and env settings

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Safe unzip, persisted seen-set state and retention

**Files (under `docker/emwin-http/internal/`):**
- Create: `unzip/unzip.go`, `unzip/unzip_test.go`
- Create: `state/state.go`, `state/state_test.go`
- Create: `retention/retention.go`, `retention/retention_test.go`

**Interfaces:**
- Produces:
  - `unzip.Limits{MaxMemberBytes, MaxTotalBytes int64; MaxMembers int}`; `unzip.ErrLimit`; `unzip.Extract(zipPath, destDir string, limits Limits, want func(name string) bool) (written []string, skipped []string, err error)` — `want` receives the sanitized name; only wanted members are written (flat, mode 0644); unsafe members (path separators, `..`, directories, empty or dotfile names) are skipped and returned in `skipped`; limits are checked over ALL members before anything is written and enforced again while copying; on any error nothing from that call remains on disk.
  - `state.Open(path string, maxSeen int) (*Store, error)` (missing file = empty; corrupt file = empty with `Recovered() == true`); `(*Store).Seen(name) bool`, `MarkSeen(name)`, `Validators() (lastModified, etag string)`, `SetValidators(lastModified, etag string)`, `Save() error` (atomic temp+rename), `Recovered() bool`.
  - `retention.Cleanup(dir string, maxAge time.Duration, now time.Time) (int, error)` — deletes regular files older than `maxAge` directly in `dir` and returns the count; a missing dir is not an error.

- [ ] **Step 1: Write the failing tests**

`unzip/unzip_test.go`:

```go
package unzip

import (
	"archive/zip"
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

func makeZip(t *testing.T, files map[string]string) string {
	t.Helper()
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	for name, body := range files {
		f, err := w.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		f.Write([]byte(body))
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "in.zip")
	if err := os.WriteFile(path, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func all(string) bool { return true }

var generous = Limits{MaxMemberBytes: 1 << 20, MaxTotalBytes: 10 << 20, MaxMembers: 100}

func listDir(t *testing.T, dir string) []string {
	t.Helper()
	entries, _ := os.ReadDir(dir)
	names := []string{}
	for _, e := range entries {
		names = append(names, e.Name())
	}
	sort.Strings(names)
	return names
}

func TestExtractWritesWantedMembers(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{"a.txt": "one", "b.txt": "two"})

	written, skipped, err := Extract(zipPath, dest, generous, func(n string) bool { return n == "b.txt" })
	if err != nil || len(skipped) != 0 {
		t.Fatalf("err=%v skipped=%v", err, skipped)
	}
	sort.Strings(written)
	if len(written) != 1 || written[0] != "b.txt" {
		t.Fatalf("written = %v", written)
	}
	if got, _ := os.ReadFile(filepath.Join(dest, "b.txt")); string(got) != "two" {
		t.Fatalf("content = %q", got)
	}
	if _, err := os.Stat(filepath.Join(dest, "a.txt")); err == nil {
		t.Fatal("unwanted member was written")
	}
}

func TestExtractSkipsUnsafeNames(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{
		"../evil.txt": "x", "a/b.txt": "x", "/abs.txt": "x", `c\d.txt`: "x", "..": "x", ".hidden": "x", "good.txt": "ok",
	})

	written, skipped, err := Extract(zipPath, dest, generous, all)
	if err != nil {
		t.Fatal(err)
	}
	if len(written) != 1 || written[0] != "good.txt" || len(skipped) != 6 {
		t.Fatalf("written=%v skipped=%v", written, skipped)
	}
	if got := listDir(t, dest); len(got) != 1 || got[0] != "good.txt" {
		t.Fatalf("dest contents = %v", got)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(dest), "evil.txt")); err == nil {
		t.Fatal("path traversal escaped the destination")
	}
}

func TestExtractSanitisesNames(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{"weird name!.txt": "x"})

	written, _, err := Extract(zipPath, dest, generous, all)
	if err != nil || len(written) != 1 || written[0] != "weird_name_.txt" {
		t.Fatalf("written=%v err=%v", written, err)
	}
}

func TestExtractEnforcesLimitsAndLeavesNothing(t *testing.T) {
	cases := map[string]struct {
		files  map[string]string
		limits Limits
	}{
		"member too big": {map[string]string{"a.txt": strings.Repeat("x", 100)}, Limits{MaxMemberBytes: 10, MaxTotalBytes: 1 << 20, MaxMembers: 10}},
		"total too big":  {map[string]string{"a.txt": strings.Repeat("x", 60), "b.txt": strings.Repeat("y", 60)}, Limits{MaxMemberBytes: 100, MaxTotalBytes: 100, MaxMembers: 10}},
		"too many":       {map[string]string{"a.txt": "1", "b.txt": "2", "c.txt": "3"}, Limits{MaxMemberBytes: 100, MaxTotalBytes: 100, MaxMembers: 2}},
	}
	for name, tc := range cases {
		dest := t.TempDir()
		_, _, err := Extract(makeZip(t, tc.files), dest, tc.limits, all)
		if !errors.Is(err, ErrLimit) {
			t.Errorf("%s: err = %v, want ErrLimit", name, err)
		}
		if got := listDir(t, dest); len(got) != 0 {
			t.Errorf("%s: files left behind: %v", name, got)
		}
	}
}

func TestExtractRejectsANonZip(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bad.zip")
	os.WriteFile(path, []byte("not a zip"), 0o600)

	if _, _, err := Extract(path, t.TempDir(), generous, all); err == nil {
		t.Fatal("expected an error")
	}
}
```

`state/state_test.go`:

```go
package state

import (
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

func TestRoundTripPersistsValidatorsAndSeen(t *testing.T) {
	path := filepath.Join(t.TempDir(), "s", "txtmin02.zip.json")

	s, err := Open(path, 100)
	if err != nil || s.Recovered() {
		t.Fatalf("open: %v recovered=%v", err, s.Recovered())
	}
	s.MarkSeen("a.txt")
	s.SetValidators("Mon, 21 Sep 2026 16:14:00 GMT", `"abc"`)
	if err := s.Save(); err != nil {
		t.Fatal(err)
	}

	again, err := Open(path, 100)
	if err != nil {
		t.Fatal(err)
	}
	lm, etag := again.Validators()
	if !again.Seen("a.txt") || again.Seen("b.txt") || lm != "Mon, 21 Sep 2026 16:14:00 GMT" || etag != `"abc"` {
		t.Fatalf("state not restored: seen=%v lm=%q etag=%q", again.Seen("a.txt"), lm, etag)
	}
}

func TestSeenSetIsBoundedOldestFirst(t *testing.T) {
	s, _ := Open(filepath.Join(t.TempDir(), "s.json"), 3)
	for i := 0; i < 5; i++ {
		s.MarkSeen(fmt.Sprintf("f%d", i))
	}
	if s.Seen("f0") || s.Seen("f1") || !s.Seen("f2") || !s.Seen("f4") {
		t.Fatal("oldest names should be evicted first")
	}
}

func TestMarkSeenIsIdempotent(t *testing.T) {
	s, _ := Open(filepath.Join(t.TempDir(), "s.json"), 2)
	s.MarkSeen("a")
	s.MarkSeen("a")
	s.MarkSeen("b")
	if !s.Seen("a") || !s.Seen("b") {
		t.Fatal("a repeated name must not evict others")
	}
}

func TestCorruptFileStartsEmptyAndIsFlagged(t *testing.T) {
	path := filepath.Join(t.TempDir(), "s.json")
	os.WriteFile(path, []byte("{not json"), 0o600)

	s, err := Open(path, 10)
	if err != nil || !s.Recovered() || s.Seen("x") {
		t.Fatalf("err=%v recovered=%v", err, s.Recovered())
	}
}

func TestSaveLeavesNoTempFiles(t *testing.T) {
	dir := t.TempDir()
	s, _ := Open(filepath.Join(dir, "s.json"), 10)
	s.MarkSeen("a")
	if err := s.Save(); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 || entries[0].Name() != "s.json" {
		t.Fatalf("unexpected directory contents: %v", entries)
	}
}
```

`retention/retention_test.go`:

```go
package retention

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestCleanupDeletesOnlyOldRegularFiles(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 9, 21, 12, 0, 0, 0, time.UTC)

	old := filepath.Join(dir, "old.txt")
	fresh := filepath.Join(dir, "fresh.txt")
	os.WriteFile(old, []byte("x"), 0o600)
	os.WriteFile(fresh, []byte("x"), 0o600)
	os.Mkdir(filepath.Join(dir, "sub"), 0o700)
	os.Chtimes(old, now.Add(-8*24*time.Hour), now.Add(-8*24*time.Hour))
	os.Chtimes(fresh, now.Add(-1*time.Hour), now.Add(-1*time.Hour))

	n, err := Cleanup(dir, 7*24*time.Hour, now)
	if err != nil || n != 1 {
		t.Fatalf("n=%d err=%v", n, err)
	}
	if _, err := os.Stat(old); err == nil {
		t.Fatal("old file should be gone")
	}
	if _, err := os.Stat(fresh); err != nil {
		t.Fatal("fresh file should remain")
	}
	if _, err := os.Stat(filepath.Join(dir, "sub")); err != nil {
		t.Fatal("subdirectory should remain")
	}
}

func TestCleanupMissingDirIsNotAnError(t *testing.T) {
	if n, err := Cleanup(filepath.Join(t.TempDir(), "absent"), time.Hour, time.Now()); err != nil || n != 0 {
		t.Fatalf("n=%d err=%v", n, err)
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd docker/emwin-http && go test ./...`
Expected: FAIL (the three new packages have no implementation).

- [ ] **Step 3: Write the implementations**

`internal/unzip/unzip.go`:

```go
// Package unzip extracts a zip into a flat directory, defensively.
package unzip

import (
	"archive/zip"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// ErrLimit means the archive breached a configured size or count limit.
var ErrLimit = errors.New("zip exceeds the configured limits")

type Limits struct {
	MaxMemberBytes int64
	MaxTotalBytes  int64
	MaxMembers     int
}

var unsafeChars = regexp.MustCompile(`[^A-Za-z0-9._-]`)

// Extract writes the wanted, safe members of the zip into destDir (flat). want receives the
// sanitised name. Members with path separators or "..", directories, and empty or dotfile
// names are skipped and reported. Limits are checked over every member before anything is
// written and enforced again while copying; on any error nothing from this call remains.
func Extract(zipPath, destDir string, limits Limits, want func(name string) bool) (written, skipped []string, err error) {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return nil, nil, fmt.Errorf("open zip: %w", err)
	}
	defer r.Close()

	if len(r.File) > limits.MaxMembers {
		return nil, nil, fmt.Errorf("%w: %d members", ErrLimit, len(r.File))
	}

	var total uint64
	for _, f := range r.File {
		if int64(f.UncompressedSize64) > limits.MaxMemberBytes {
			return nil, nil, fmt.Errorf("%w: member %q is %d bytes", ErrLimit, f.Name, f.UncompressedSize64)
		}
		total += f.UncompressedSize64
		if int64(total) > limits.MaxTotalBytes || total > uint64(limits.MaxTotalBytes) {
			return nil, nil, fmt.Errorf("%w: total uncompressed size", ErrLimit)
		}
	}

	if err := os.MkdirAll(destDir, 0o755); err != nil {
		return nil, nil, fmt.Errorf("create destination: %w", err)
	}

	created := []string{}
	cleanup := func() {
		for _, p := range created {
			os.Remove(p)
		}
	}
	seenNames := map[string]bool{}

	for _, f := range r.File {
		name, ok := safeName(f)
		if !ok || seenNames[name] {
			skipped = append(skipped, f.Name)
			continue
		}
		seenNames[name] = true

		if !want(name) {
			continue
		}

		final := filepath.Join(destDir, name)
		if err := copyMember(f, final, limits.MaxMemberBytes); err != nil {
			cleanup()
			return nil, nil, err
		}
		created = append(created, final)
		written = append(written, name)
	}

	return written, skipped, nil
}

func safeName(f *zip.File) (string, bool) {
	if f.FileInfo().IsDir() || f.Name == "" {
		return "", false
	}
	if strings.ContainsAny(f.Name, `/\`) || strings.Contains(f.Name, "..") {
		return "", false
	}
	name := unsafeChars.ReplaceAllString(f.Name, "_")
	if name == "" || strings.HasPrefix(name, ".") {
		return "", false
	}
	return name, true
}

func copyMember(f *zip.File, final string, maxBytes int64) error {
	in, err := f.Open()
	if err != nil {
		return fmt.Errorf("open member %q: %w", f.Name, err)
	}
	defer in.Close()

	tmp, err := os.CreateTemp(filepath.Dir(final), ".tmp-*")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}
	tmpName := tmp.Name()

	n, copyErr := io.Copy(tmp, io.LimitReader(in, maxBytes+1))
	closeErr := tmp.Close()

	switch {
	case copyErr != nil:
		os.Remove(tmpName)
		return fmt.Errorf("copy member %q: %w", f.Name, copyErr)
	case n > maxBytes:
		os.Remove(tmpName)
		return fmt.Errorf("%w: member %q grew past the limit while copying", ErrLimit, f.Name)
	case closeErr != nil:
		os.Remove(tmpName)
		return closeErr
	}

	if err := os.Chmod(tmpName, 0o644); err != nil {
		os.Remove(tmpName)
		return err
	}
	if err := os.Rename(tmpName, final); err != nil {
		os.Remove(tmpName)
		return err
	}
	return nil
}
```

`internal/state/state.go`:

```go
// Package state persists, per zip file, the conditional-GET validators and a bounded set of
// product names already sent to the pipeline.
package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
)

type fileState struct {
	LastModified string   `json:"last_modified"`
	ETag         string   `json:"etag"`
	Seen         []string `json:"seen"`
}

type Store struct {
	mu        sync.Mutex
	path      string
	maxSeen   int
	st        fileState
	set       map[string]struct{}
	recovered bool
}

// Open loads the state file. A missing file is an empty state; a corrupt one is treated as
// empty and flagged via Recovered().
func Open(path string, maxSeen int) (*Store, error) {
	s := &Store{path: path, maxSeen: maxSeen, set: map[string]struct{}{}}

	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}

	if err := json.Unmarshal(data, &s.st); err != nil {
		s.st = fileState{}
		s.recovered = true
		return s, nil
	}
	for _, name := range s.st.Seen {
		s.set[name] = struct{}{}
	}
	return s, nil
}

func (s *Store) Recovered() bool { return s.recovered }

func (s *Store) Seen(name string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, ok := s.set[name]
	return ok
}

// MarkSeen records a name, evicting the oldest names beyond the bound.
func (s *Store) MarkSeen(name string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, ok := s.set[name]; ok {
		return
	}
	s.set[name] = struct{}{}
	s.st.Seen = append(s.st.Seen, name)
	for len(s.st.Seen) > s.maxSeen {
		delete(s.set, s.st.Seen[0])
		s.st.Seen = s.st.Seen[1:]
	}
}

func (s *Store) Validators() (lastModified, etag string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.st.LastModified, s.st.ETag
}

func (s *Store) SetValidators(lastModified, etag string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.st.LastModified, s.st.ETag = lastModified, etag
}

// Save writes the state atomically (temp file + rename).
func (s *Store) Save() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	data, err := json.Marshal(s.st)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(s.path), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(s.path), ".state-*")
	if err != nil {
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		os.Remove(tmp.Name())
		return err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	return os.Rename(tmp.Name(), s.path)
}
```

`internal/retention/retention.go`:

```go
// Package retention removes files that have outlived the retention period.
package retention

import (
	"errors"
	"os"
	"path/filepath"
	"time"
)

// Cleanup deletes regular files directly in dir older than maxAge and returns how many.
// Subdirectories are left alone; a missing directory is not an error.
func Cleanup(dir string, maxAge time.Duration, now time.Time) (int, error) {
	entries, err := os.ReadDir(dir)
	if errors.Is(err, os.ErrNotExist) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}

	deleted := 0
	for _, e := range entries {
		if !e.Type().IsRegular() {
			continue
		}
		info, err := e.Info()
		if err != nil || now.Sub(info.ModTime()) <= maxAge {
			continue
		}
		if err := os.Remove(filepath.Join(dir, e.Name())); err == nil {
			deleted++
		}
	}
	return deleted, nil
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd docker/emwin-http && gofmt -l . && go vet ./... && go test ./...`
Expected: PASS. If the "total too big" case does not trip (its two members are 60 bytes with a total limit of 100), fix the limit logic, not the test. If `go vet` flags the redundant total comparison in `Extract`, simplify it to a single `total > uint64(limits.MaxTotalBytes)` check.

- [ ] **Step 5: Commit**

```bash
git add docker/emwin-http/internal
git commit -m "$(cat <<'EOF'
Add safe unzip, persisted seen-set state and retention to the EMWIN HTTP client

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Fetcher, poster, cycle/loop runner, healthcheck and main

**Files (under `docker/emwin-http/`):**
- Create: `internal/fetch/fetch.go`, `internal/fetch/fetch_test.go`
- Create: `internal/poster/poster.go`, `internal/poster/poster_test.go`
- Create: `internal/runner/runner.go`, `internal/runner/runner_test.go`
- Create: `internal/health/health.go`, `internal/health/health_test.go`
- Create: `cmd/emwin-http/main.go`

**Interfaces:**
- Consumes: everything from Tasks 1-2.
- Produces:
  - `fetch.Fetcher{Client *http.Client; URL string; MaxBytes int64}` with `Fetch(ctx, lastModified, etag, dest string) (fetch.Result, error)`; `fetch.Result{NotModified bool; LastModified, ETag string}`; `fetch.ErrTooLarge`; `*fetch.StatusError{Code int}`.
  - `poster.Poster{Client *http.Client; URL, Token string}` with `Post(ctx, relPath string) error` (POSTs `{"path": relPath, "feed": "http"}`, bearer auth, any 2xx is success).
  - `runner.Fetcher` and `runner.Poster` interfaces; `runner.Deps`; `runner.RunCycle(ctx, Deps) error`; `runner.Loop(ctx, name string, cadence time.Duration, cycle func(context.Context) error, onSuccess func(), sleep func(context.Context, time.Duration) bool, log *slog.Logger)`.
  - `health.Mark(dir, name string, now time.Time) error` and `health.Check(dir string, names []string, cadences map[string]time.Duration, now time.Time) error` (fails if any enabled zip has no marker or a marker older than 3 × its cadence).

- [ ] **Step 1: Write the failing tests**

`internal/fetch/fetch_test.go`:

```go
package fetch

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newFetcher(url string, max int64) *Fetcher {
	return &Fetcher{Client: http.DefaultClient, URL: url, MaxBytes: max}
}

func TestFetchStoresBodyAndValidators(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-Modified-Since") != "" || r.Header.Get("If-None-Match") != "" {
			t.Error("first request must be unconditional")
		}
		w.Header().Set("Last-Modified", "Mon, 21 Sep 2026 16:14:00 GMT")
		w.Header().Set("ETag", `"v1"`)
		w.Write([]byte("zipbytes"))
	}))
	defer srv.Close()

	dest := filepath.Join(t.TempDir(), "out.zip")
	res, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "", "", dest)
	if err != nil || res.NotModified || res.LastModified != "Mon, 21 Sep 2026 16:14:00 GMT" || res.ETag != `"v1"` {
		t.Fatalf("res=%+v err=%v", res, err)
	}
	if got, _ := os.ReadFile(dest); string(got) != "zipbytes" {
		t.Fatalf("body = %q", got)
	}
}

func TestFetchSendsValidatorsAndHandles304(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-Modified-Since") != "Mon, 21 Sep 2026 16:14:00 GMT" || r.Header.Get("If-None-Match") != `"v1"` {
			t.Errorf("validators not sent: %v", r.Header)
		}
		w.WriteHeader(http.StatusNotModified)
	}))
	defer srv.Close()

	res, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "Mon, 21 Sep 2026 16:14:00 GMT", `"v1"`, filepath.Join(t.TempDir(), "o.zip"))
	if err != nil || !res.NotModified {
		t.Fatalf("res=%+v err=%v", res, err)
	}
}

func TestFetchReportsHTTPErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()

	_, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "", "", filepath.Join(t.TempDir(), "o.zip"))
	var se *StatusError
	if !errors.As(err, &se) || se.Code != 503 {
		t.Fatalf("err = %v", err)
	}
}

func TestFetchEnforcesTheSizeCapAndLeavesNoFile(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(strings.Repeat("x", 200)))
	}))
	defer srv.Close()

	dest := filepath.Join(t.TempDir(), "o.zip")
	_, err := newFetcher(srv.URL, 100).Fetch(context.Background(), "", "", dest)
	if !errors.Is(err, ErrTooLarge) {
		t.Fatalf("err = %v", err)
	}
	if _, statErr := os.Stat(dest); statErr == nil {
		t.Fatal("partial file left behind")
	}
}
```

`internal/poster/poster_test.go`:

```go
package poster

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestPostSendsPathFeedAndBearer(t *testing.T) {
	var got map[string]string
	var auth string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		body, _ := io.ReadAll(r.Body)
		json.Unmarshal(body, &got)
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	p := &Poster{Client: http.DefaultClient, URL: srv.URL, Token: "sekret"}
	if err := p.Post(context.Background(), "http/A_FOO.TXT"); err != nil {
		t.Fatal(err)
	}
	if auth != "Bearer sekret" || got["path"] != "http/A_FOO.TXT" || got["feed"] != "http" {
		t.Fatalf("auth=%q body=%v", auth, got)
	}
}

func TestPostFailsOnNon2xxWithoutLeakingTheToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	err := (&Poster{Client: http.DefaultClient, URL: srv.URL, Token: "sekret"}).Post(context.Background(), "http/x")
	if err == nil || strings.Contains(err.Error(), "sekret") || !strings.Contains(err.Error(), "500") {
		t.Fatalf("err = %v", err)
	}
}
```

`internal/health/health_test.go`:

```go
package health

import (
	"testing"
	"time"
)

func TestCheckPassesWithFreshMarkers(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 9, 21, 12, 0, 0, 0, time.UTC)
	Mark(dir, "txtmin02.zip", now.Add(-4*time.Minute))

	err := Check(dir, []string{"txtmin02.zip"}, map[string]time.Duration{"txtmin02.zip": 2 * time.Minute}, now)
	if err != nil {
		t.Fatal(err)
	}
}

func TestCheckFailsWhenStaleOrMissing(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 9, 21, 12, 0, 0, 0, time.UTC)
	cad := map[string]time.Duration{"txtmin02.zip": 2 * time.Minute, "imgmin15.zip": 15 * time.Minute}

	Mark(dir, "txtmin02.zip", now.Add(-7*time.Minute)) // older than 3 x 2 minutes

	if err := Check(dir, []string{"txtmin02.zip"}, cad, now); err == nil {
		t.Fatal("a stale marker should fail")
	}
	if err := Check(dir, []string{"imgmin15.zip"}, cad, now); err == nil {
		t.Fatal("a missing marker should fail")
	}
}

func TestCheckWithNothingEnabledPasses(t *testing.T) {
	if err := Check(t.TempDir(), nil, nil, time.Now()); err != nil {
		t.Fatal(err)
	}
}
```

`internal/runner/runner_test.go`:

```go
package runner

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"testing"
	"time"

	"wxnotify.local/emwin-http/internal/fetch"
	"wxnotify.local/emwin-http/internal/state"
	"wxnotify.local/emwin-http/internal/unzip"
)

type fakeFetcher struct {
	zipBytes    []byte
	notModified bool
	err         error
	calls       int
	gotLM       string
}

func (f *fakeFetcher) Fetch(ctx context.Context, lm, etag, dest string) (fetch.Result, error) {
	f.calls++
	f.gotLM = lm
	if f.err != nil {
		return fetch.Result{}, f.err
	}
	if f.notModified {
		return fetch.Result{NotModified: true}, nil
	}
	if err := os.WriteFile(dest, f.zipBytes, 0o600); err != nil {
		return fetch.Result{}, err
	}
	return fetch.Result{LastModified: "LM1", ETag: `"E1"`}, nil
}

type fakePoster struct {
	posted []string
	fail   map[string]bool
}

func (p *fakePoster) Post(ctx context.Context, rel string) error {
	if p.fail[rel] {
		return errors.New("pipeline unavailable")
	}
	p.posted = append(p.posted, rel)
	return nil
}

func zipOf(t *testing.T, names ...string) []byte {
	t.Helper()
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	for _, n := range names {
		f, _ := w.Create(n)
		io.WriteString(f, "body of "+n)
	}
	w.Close()
	return buf.Bytes()
}

func newDeps(t *testing.T, f Fetcher, p Poster) (Deps, *state.Store) {
	t.Helper()
	dir := t.TempDir()
	store, _ := state.Open(filepath.Join(dir, "state.json"), 1000)
	return Deps{
		Fetcher:    f,
		Poster:     p,
		Store:      store,
		Limits:     unzip.Limits{MaxMemberBytes: 1 << 20, MaxTotalBytes: 10 << 20, MaxMembers: 100},
		StorageDir: filepath.Join(dir, "http"),
		RelPrefix:  "http",
		TempDir:    dir,
		Log:        slog.New(slog.NewTextHandler(io.Discard, nil)),
	}, store
}

func sorted(s []string) []string { sort.Strings(s); return s }

func TestCyclePostsNewFilesAndRecordsValidators(t *testing.T) {
	f := &fakeFetcher{zipBytes: zipOf(t, "A.TXT", "B.TXT")}
	p := &fakePoster{}
	d, store := newDeps(t, f, p)

	if err := RunCycle(context.Background(), d); err != nil {
		t.Fatal(err)
	}
	if got := sorted(p.posted); len(got) != 2 || got[0] != "http/A.TXT" || got[1] != "http/B.TXT" {
		t.Fatalf("posted = %v", got)
	}
	if lm, _ := store.Validators(); lm != "LM1" {
		t.Fatalf("validators not recorded: %q", lm)
	}
	if !store.Seen("A.TXT") || !store.Seen("B.TXT") {
		t.Fatal("posted files must be marked seen")
	}
}

func TestSecondCycleOnlyPostsUnseenFiles(t *testing.T) {
	f := &fakeFetcher{zipBytes: zipOf(t, "A.TXT")}
	p := &fakePoster{}
	d, _ := newDeps(t, f, p)
	RunCycle(context.Background(), d)

	f.zipBytes = zipOf(t, "A.TXT", "B.TXT") // overlapping batch
	p.posted = nil
	if err := RunCycle(context.Background(), d); err != nil {
		t.Fatal(err)
	}
	if len(p.posted) != 1 || p.posted[0] != "http/B.TXT" {
		t.Fatalf("posted = %v", p.posted)
	}
}

func TestNotModifiedSkipsEverything(t *testing.T) {
	f := &fakeFetcher{notModified: true}
	p := &fakePoster{}
	d, _ := newDeps(t, f, p)

	if err := RunCycle(context.Background(), d); err != nil || len(p.posted) != 0 {
		t.Fatalf("err=%v posted=%v", err, p.posted)
	}
}

func TestFailedPostLeavesFileUnseenAndValidatorsUnrecorded(t *testing.T) {
	f := &fakeFetcher{zipBytes: zipOf(t, "A.TXT", "B.TXT")}
	p := &fakePoster{fail: map[string]bool{"http/B.TXT": true}}
	d, store := newDeps(t, f, p)

	if err := RunCycle(context.Background(), d); err == nil {
		t.Fatal("a failed post must make the cycle fail")
	}
	if !store.Seen("A.TXT") || store.Seen("B.TXT") {
		t.Fatal("only the accepted file may be marked seen")
	}
	if lm, _ := store.Validators(); lm != "" {
		t.Fatal("validators must not be recorded after a failed post")
	}

	// The next cycle re-fetches (no validators) and posts only the unseen file.
	p.fail = nil
	p.posted = nil
	if err := RunCycle(context.Background(), d); err != nil {
		t.Fatal(err)
	}
	if len(p.posted) != 1 || p.posted[0] != "http/B.TXT" {
		t.Fatalf("posted = %v", p.posted)
	}
	if f.gotLM != "" {
		t.Fatalf("retry must be unconditional, sent %q", f.gotLM)
	}
}

func TestFetchErrorsPropagate(t *testing.T) {
	f := &fakeFetcher{err: errors.New("network down")}
	d, _ := newDeps(t, f, &fakePoster{})

	if err := RunCycle(context.Background(), d); err == nil {
		t.Fatal("expected the fetch error")
	}
}

func TestOversizedZipIsDiscarded(t *testing.T) {
	f := &fakeFetcher{zipBytes: zipOf(t, "A.TXT")}
	p := &fakePoster{}
	d, _ := newDeps(t, f, p)
	d.Limits.MaxMembers = 0

	if err := RunCycle(context.Background(), d); !errors.Is(err, unzip.ErrLimit) {
		t.Fatalf("err = %v", err)
	}
	if len(p.posted) != 0 {
		t.Fatal("nothing may be posted from a discarded zip")
	}
}

func TestImageModeWritesFilesButPostsNothing(t *testing.T) {
	f := &fakeFetcher{zipBytes: zipOf(t, "IMG1.PNG")}
	d, store := newDeps(t, f, nil)
	d.RelPrefix = ""

	if err := RunCycle(context.Background(), d); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(d.StorageDir, "IMG1.PNG")); err != nil {
		t.Fatal("image file should be written")
	}
	if !store.Seen("IMG1.PNG") {
		t.Fatal("image files are still tracked so they are not re-extracted")
	}
}

func TestLoopBacksOffOnFailureAndResetsOnSuccess(t *testing.T) {
	results := []error{errors.New("a"), errors.New("b"), nil}
	i := 0
	cycle := func(ctx context.Context) error {
		err := results[i]
		i++
		return err
	}

	var sleeps []time.Duration
	successes := 0
	sleep := func(ctx context.Context, d time.Duration) bool {
		sleeps = append(sleeps, d)
		return len(sleeps) < 3 // stop after the third sleep
	}

	Loop(context.Background(), "txtmin02.zip", 2*time.Minute, cycle, func() { successes++ }, sleep, slog.New(slog.NewTextHandler(io.Discard, nil)))

	want := []time.Duration{30 * time.Second, time.Minute, 2 * time.Minute}
	if len(sleeps) != 3 || sleeps[0] != want[0] || sleeps[1] != want[1] || sleeps[2] != want[2] {
		t.Fatalf("sleeps = %v, want %v", sleeps, want)
	}
	if successes != 1 {
		t.Fatalf("successes = %d", successes)
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd docker/emwin-http && go test ./...`
Expected: FAIL (the new packages have no implementation).

- [ ] **Step 3: Write the fetcher, poster and health packages**

`internal/fetch/fetch.go`:

```go
// Package fetch downloads a zip with conditional-GET support.
package fetch

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
)

var ErrTooLarge = errors.New("download exceeds the size cap")

type StatusError struct{ Code int }

func (e *StatusError) Error() string { return fmt.Sprintf("unexpected HTTP status %d", e.Code) }

type Result struct {
	NotModified  bool
	LastModified string
	ETag         string
}

type Fetcher struct {
	Client   *http.Client
	URL      string
	MaxBytes int64
}

// Fetch downloads the file to dest. When lastModified or etag are set they are sent as
// conditional headers; a 304 returns NotModified without touching dest. A body larger than
// MaxBytes fails with ErrTooLarge and leaves no file behind.
func (f *Fetcher) Fetch(ctx context.Context, lastModified, etag, dest string) (Result, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, f.URL, nil)
	if err != nil {
		return Result{}, err
	}
	req.Header.Set("User-Agent", "wxnotify-emwin-http")
	if lastModified != "" {
		req.Header.Set("If-Modified-Since", lastModified)
	}
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}

	resp, err := f.Client.Do(req)
	if err != nil {
		return Result{}, err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusNotModified:
		return Result{NotModified: true}, nil
	case http.StatusOK:
	default:
		return Result{}, &StatusError{Code: resp.StatusCode}
	}

	out, err := os.Create(dest)
	if err != nil {
		return Result{}, err
	}

	n, copyErr := io.Copy(out, io.LimitReader(resp.Body, f.MaxBytes+1))
	closeErr := out.Close()

	if copyErr != nil || closeErr != nil || n > f.MaxBytes {
		os.Remove(dest)
		switch {
		case n > f.MaxBytes:
			return Result{}, ErrTooLarge
		case copyErr != nil:
			return Result{}, copyErr
		default:
			return Result{}, closeErr
		}
	}

	return Result{LastModified: resp.Header.Get("Last-Modified"), ETag: resp.Header.Get("ETag")}, nil
}
```

`internal/poster/poster.go`:

```go
// Package poster tells the Laravel alert pipeline about a new product file.
package poster

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
)

type Poster struct {
	Client *http.Client
	URL    string
	Token  string
}

// Post sends the product's path (relative to the shared products root) to the pipeline.
// Any 2xx is success; the error never includes the token.
func (p *Poster) Post(ctx context.Context, relPath string) error {
	body, err := json.Marshal(map[string]string{"path": relPath, "feed": "http"})
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, p.URL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+p.Token)

	resp, err := p.Client.Do(req)
	if err != nil {
		return fmt.Errorf("post %q: %w", relPath, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return fmt.Errorf("post %q: pipeline answered HTTP %d", relPath, resp.StatusCode)
	}
	return nil
}
```

`internal/health/health.go`:

```go
// Package health records the last successful poll of each zip and checks freshness.
package health

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// Mark records a successful poll of the named zip at the given time.
func Mark(dir, name string, now time.Time) error {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	path := filepath.Join(dir, name+".ok")
	if err := os.WriteFile(path, []byte(now.UTC().Format(time.RFC3339)), 0o644); err != nil {
		return err
	}
	return os.Chtimes(path, now, now)
}

// Check fails if any listed zip has no marker or one older than three of its cadences.
// A 304 counts as a successful poll, so a quiet server does not look unhealthy.
func Check(dir string, names []string, cadences map[string]time.Duration, now time.Time) error {
	for _, name := range names {
		info, err := os.Stat(filepath.Join(dir, name+".ok"))
		if err != nil {
			return fmt.Errorf("no successful poll of %s yet", name)
		}
		if limit := 3 * cadences[name]; now.Sub(info.ModTime()) > limit {
			return fmt.Errorf("last successful poll of %s was %s ago (limit %s)", name, now.Sub(info.ModTime()).Round(time.Second), limit)
		}
	}
	return nil
}
```

- [ ] **Step 4: Write the runner**

`internal/runner/runner.go`:

```go
// Package runner performs one poll cycle and the loop that repeats it on a cadence.
package runner

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"time"

	"wxnotify.local/emwin-http/internal/fetch"
	"wxnotify.local/emwin-http/internal/state"
	"wxnotify.local/emwin-http/internal/unzip"
)

type Fetcher interface {
	Fetch(ctx context.Context, lastModified, etag, dest string) (fetch.Result, error)
}

type Poster interface {
	Post(ctx context.Context, relPath string) error
}

type Deps struct {
	Fetcher    Fetcher
	Poster     Poster // nil in image mode: files are written but nothing is posted
	Store      *state.Store
	Limits     unzip.Limits
	StorageDir string
	RelPrefix  string // "http" for text; the posted path is RelPrefix + "/" + name
	TempDir    string
	Log        *slog.Logger
}

// RunCycle performs one conditional download and processes the new products. It returns nil
// on success (including "not modified") and an error when anything should be retried: the
// validators are only recorded when every post succeeded, so a failed cycle re-fetches and
// posts only the files not yet accepted.
func RunCycle(ctx context.Context, d Deps) error {
	lastModified, etag := d.Store.Validators()

	tmp, err := os.CreateTemp(d.TempDir, "emwin-*.zip")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}
	tmp.Close()
	defer os.Remove(tmp.Name())

	res, err := d.Fetcher.Fetch(ctx, lastModified, etag, tmp.Name())
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	if res.NotModified {
		return nil
	}

	written, skipped, err := unzip.Extract(tmp.Name(), d.StorageDir, d.Limits, func(name string) bool {
		return !d.Store.Seen(name)
	})
	if err != nil {
		return fmt.Errorf("unzip: %w", err)
	}
	if len(skipped) > 0 {
		d.Log.Warn("skipped unsafe zip members", "count", len(skipped))
	}

	failures := 0
	for _, name := range written {
		if d.Poster != nil {
			if err := d.Poster.Post(ctx, d.RelPrefix+"/"+name); err != nil {
				d.Log.Warn("post failed", "file", name, "error", err.Error())
				failures++
				continue
			}
		}
		d.Store.MarkSeen(name)
	}

	if failures == 0 {
		d.Store.SetValidators(res.LastModified, res.ETag)
	}
	if err := d.Store.Save(); err != nil {
		return fmt.Errorf("save state: %w", err)
	}

	d.Log.Info("cycle complete", "new_files", len(written), "failed", failures)

	if failures > 0 {
		return fmt.Errorf("%d of %d products failed to post", failures, len(written))
	}
	return nil
}

const backoffBase = 30 * time.Second

// Loop repeats cycle until sleep reports cancellation. After a success it waits one cadence;
// after a failure it waits 30s, 60s, 120s... capped at the cadence, resetting on success.
// sleep returns false when the context is done (or, in tests, to stop the loop).
func Loop(ctx context.Context, name string, cadence time.Duration, cycle func(context.Context) error, onSuccess func(), sleep func(context.Context, time.Duration) bool, log *slog.Logger) {
	failures := 0
	for {
		wait := cadence
		if err := cycle(ctx); err != nil {
			failures++
			wait = backoffBase << (failures - 1)
			if wait > cadence || wait <= 0 {
				wait = cadence
			}
			log.Error("cycle failed", "zip", name, "error", err.Error(), "retry_in", wait.String())
		} else {
			failures = 0
			onSuccess()
		}

		if !sleep(ctx, wait) {
			return
		}
	}
}

// Sleep waits for d or until the context is cancelled, reporting whether to keep going.
func Sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}
```

- [ ] **Step 5: Write `main`**

`cmd/emwin-http/main.go`:

```go
// Command emwin-http polls NOAA's public EMWIN HTTP zip batches and feeds new products to the
// WxNotify alert pipeline. It is the backup to the Byteblaster feed.
package main

import (
	"context"
	"flag"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"sync"
	"syscall"
	"time"

	"wxnotify.local/emwin-http/internal/cadence"
	"wxnotify.local/emwin-http/internal/config"
	"wxnotify.local/emwin-http/internal/fetch"
	"wxnotify.local/emwin-http/internal/health"
	"wxnotify.local/emwin-http/internal/poster"
	"wxnotify.local/emwin-http/internal/retention"
	"wxnotify.local/emwin-http/internal/runner"
	"wxnotify.local/emwin-http/internal/state"
	"wxnotify.local/emwin-http/internal/unzip"
)

const maxSeen = 50000

func main() {
	configPath := flag.String("config", "/config/config.json", "path to the JSON config file")
	healthcheck := flag.Bool("healthcheck", false, "exit 0 if every enabled zip was polled recently, else 1")
	flag.Parse()

	log := slog.New(slog.NewTextHandler(os.Stdout, nil))

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Error("invalid configuration", "error", err.Error())
		os.Exit(1)
	}
	env, err := config.FromEnv(os.Getenv)
	if err != nil {
		log.Error("invalid environment", "error", err.Error())
		os.Exit(1)
	}

	type job struct {
		zip    string
		images bool
	}
	var jobs []job
	if cfg.DownloadText {
		jobs = append(jobs, job{zip: cfg.TextZip})
	}
	if cfg.DownloadImages {
		jobs = append(jobs, job{zip: cfg.ImageZip, images: true})
	}

	cadences := map[string]time.Duration{}
	names := []string{}
	for _, j := range jobs {
		cadences[j.zip], _ = cadence.ForZip(j.zip)
		names = append(names, j.zip)
	}
	healthDir := filepath.Join(env.StateDir, "health")

	if *healthcheck {
		if err := health.Check(healthDir, names, cadences, time.Now()); err != nil {
			log.Error("unhealthy", "error", err.Error())
			os.Exit(1)
		}
		return
	}

	if cfg.DownloadText && (env.WebhookURL == "" || env.WebhookToken == "") {
		log.Error("EMWIN_WEBHOOK_URL and PIPELINE_WEBHOOK_TOKEN are required to post text products")
		os.Exit(1)
	}
	if len(jobs) == 0 {
		log.Warn("both download_text and download_images are false; nothing to do")
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	client := &http.Client{Timeout: 60 * time.Second}
	limits := unzip.Limits{MaxMemberBytes: env.MaxMemberBytes, MaxTotalBytes: env.MaxTotalBytes, MaxMembers: env.MaxMembers}
	maxAge := time.Duration(env.RetentionDays) * 24 * time.Hour

	var wg sync.WaitGroup
	for _, j := range jobs {
		store, err := state.Open(filepath.Join(env.StateDir, j.zip+".json"), maxSeen)
		if err != nil {
			log.Error("open state", "zip", j.zip, "error", err.Error())
			os.Exit(1)
		}
		if store.Recovered() {
			log.Warn("state file was corrupt; starting empty", "zip", j.zip)
		}

		storageDir, relPrefix := cfg.StorageDir, "http"
		var p runner.Poster
		if j.images {
			storageDir, relPrefix = cfg.ImagesDir(), ""
		} else {
			p = &poster.Poster{Client: client, URL: env.WebhookURL, Token: env.WebhookToken}
		}

		deps := runner.Deps{
			Fetcher:    &fetch.Fetcher{Client: client, URL: cfg.URLFor(j.zip), MaxBytes: env.MaxZipBytes},
			Poster:     p,
			Store:      store,
			Limits:     limits,
			StorageDir: storageDir,
			RelPrefix:  relPrefix,
			TempDir:    os.TempDir(),
			Log:        log.With("zip", j.zip),
		}

		wg.Add(1)
		go func(zip string, cad time.Duration, deps runner.Deps, dir string) {
			defer wg.Done()
			runner.Loop(ctx, zip, cad,
				func(ctx context.Context) error {
					err := runner.RunCycle(ctx, deps)
					if n, cerr := retention.Cleanup(dir, maxAge, time.Now()); cerr == nil && n > 0 {
						deps.Log.Info("retention cleanup", "deleted", n)
					}
					return err
				},
				func() { health.Mark(healthDir, zip, time.Now()) },
				runner.Sleep, deps.Log)
		}(j.zip, cadences[j.zip], deps, storageDir)
	}

	log.Info("emwin-http started", "text", cfg.DownloadText, "images", cfg.DownloadImages)
	wg.Wait()
}
```

- [ ] **Step 6: Run everything to verify it passes**

Run: `cd docker/emwin-http && gofmt -l . && go vet ./... && go test ./... && go build -o /dev/null ./cmd/emwin-http`
Expected: all packages PASS, no gofmt output, the binary builds. Fix compile or logic errors minimally (the code above was not compiled when written); do not weaken a test. The `Loop` test proves the backoff sequence 30s, 60s, 2m and the reset on success.

- [ ] **Step 7: Commit**

```bash
git add docker/emwin-http/internal docker/emwin-http/cmd
git commit -m "$(cat <<'EOF'
Add the EMWIN HTTP client's fetcher, poster, runner and healthcheck

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Container packaging, config file and compose service

**Files:**
- Create: `docker/emwin-http/Dockerfile`
- Create: `docker/emwin-http/config.json`
- Modify: `compose.yaml` (new `emwin-http` service and `emwin-http-state` volume)
- Modify: `.env.example` (only if it documents `EMWIN_RETENTION_DAYS`; otherwise leave it)

**Interfaces:**
- Consumes: the Go module (Tasks 1-3); the existing `emwin-products` volume; env `PIPELINE_WEBHOOK_TOKEN`.
- Produces: an image whose entrypoint is `/emwin-http` reading `/config/config.json`; container name `wxnotify-emwin-http` (Pipeline Control relies on this exact name).

- [ ] **Step 1: Write the Dockerfile**

`docker/emwin-http/Dockerfile` (build context is the repo root, like the other services):

```dockerfile
FROM golang:1.27 AS build
WORKDIR /src
COPY docker/emwin-http/go.mod ./
COPY docker/emwin-http/cmd ./cmd
COPY docker/emwin-http/internal ./internal
RUN go vet ./... && go test ./...
RUN CGO_ENABLED=0 go build -o /emwin-http ./cmd/emwin-http

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata
COPY --from=build /emwin-http /emwin-http
COPY docker/emwin-http/config.json /config/config.json
HEALTHCHECK --interval=60s --timeout=5s --start-period=120s --retries=3 CMD ["/emwin-http", "--healthcheck"]
ENTRYPOINT ["/emwin-http"]
```

- [ ] **Step 2: Write the default config file**

`docker/emwin-http/config.json`:

```json
{
  "server_host": "tgftp.nws.noaa.gov",
  "server_path": "/SL.us008001/CU.EMWIN/DF.xt/DC.gsatR/OPS/",
  "storage_dir": "/emwin-products/http",
  "download_text": true,
  "download_images": false,
  "text_zip": "txtmin02.zip",
  "image_zip": "imgmin15.zip"
}
```

- [ ] **Step 3: Add the compose service and volume**

In `compose.yaml`, add this service right after the `emwin:` service (same indentation and quoting style as its neighbours):

```yaml
    emwin-http:
        build:
            context: .
            dockerfile: docker/emwin-http/Dockerfile
        container_name: wxnotify-emwin-http
        environment:
            PIPELINE_WEBHOOK_TOKEN: '${PIPELINE_WEBHOOK_TOKEN}'
            EMWIN_WEBHOOK_URL: 'http://laravel.test/api/pipeline/emwin-products'
            EMWIN_HTTP_STATE_DIR: /state
            EMWIN_RETENTION_DAYS: '${EMWIN_RETENTION_DAYS:-7}'
        volumes:
            - 'emwin-products:/emwin-products'
            - 'emwin-http-state:/state'
            - './docker/emwin-http/config.json:/config/config.json:ro'
        networks:
            - sail
        restart: unless-stopped
        depends_on:
            - laravel.test
```

and in the top-level `volumes:` list add `emwin-http-state:` with the same driver block the neighbouring volumes use (read how `emwin-products:` is declared and mirror it exactly).

- [ ] **Step 4: Verify the build and the compose file (do NOT start the container)**

Run: `docker compose config --quiet` (must exit 0) and `docker build -f docker/emwin-http/Dockerfile -t wxnotify-emwin-http:verify .` (the build runs `go vet` and `go test` in the build stage; it must succeed). Report both outputs. Then remove the throwaway tag: `docker rmi wxnotify-emwin-http:verify`. Do not run `docker compose up` for this service (it would poll the real NOAA server and post to the dev pipeline).

- [ ] **Step 5: Check the binary's startup behaviours with a throwaway container (no network use)**

Run: `docker run --rm wxnotify-emwin-http:verify --config /config/config.json --healthcheck` BEFORE removing the tag in Step 4 (reorder as needed) and confirm it exits 1 with "no successful poll of txtmin02.zip yet" (a fresh container has no marker). Also run it with a bad config mounted (`-v $(mktemp -d)/bad.json:/config/config.json` containing `{"server_host":"https://x"}`) and confirm it refuses to start with a message naming `server_host`. Report the outputs.

- [ ] **Step 6: Commit**

```bash
git add docker/emwin-http/Dockerfile docker/emwin-http/config.json compose.yaml
git commit -m "$(cat <<'EOF'
Package the EMWIN HTTP client as a container and compose service

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Laravel side — the `feed` parameter, heartbeat and Pipeline Control row

**Files:**
- Modify: `app/Http/Controllers/IngestEmwinProductController.php`
- Modify: `app/Filament/Admin/Pages/PipelineControl.php`
- Modify: `resources/views/filament/admin/pages/pipeline-control.blade.php`
- Modify: `app/Filament/Admin/Pages/ResetToFactoryDefaults.php` (the "I have stopped the pipeline services (...)" checkbox label)
- Test: `tests/Feature/IngestEmwinProductControllerTest.php`, `tests/Feature/PipelineControlTest.php` (read both first and follow their setup patterns), and `tests/Feature/ResetToFactoryDefaultsPageTest.php` if it asserts the label text.

**Interfaces:**
- Consumes: the client's request body `{"path": "http/<name>", "feed": "http"}`; `PipelineHeartbeat::record(string)` / `lastReceivedAt(string)`; `DockerEngineClient` (`status`, `start`, `stop`, `restart`).
- Produces: `IngestEmwinProductController` accepts optional `feed` (`byteblaster` default | `http`), recording heartbeat key `emwin` or `emwin_http`; Pipeline Control shows a real "EMWIN HTTP backup" row for container `wxnotify-emwin-http` with status, start/stop/restart and "Last product".

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/IngestEmwinProductControllerTest.php` (mirror the existing tests' file/path setup and helper functions; the assertions below are what must hold):
- posting `{"path": <valid relative path>}` with no `feed` records the heartbeat `PipelineHeartbeat::lastReceivedAt('emwin')` and NOT `'emwin_http'` (existing behavior, now asserted explicitly);
- posting with `"feed": "http"` records `'emwin_http'` and NOT `'emwin'`, and still ingests the product with `source === 'emwin'`;
- posting with `"feed": "byteblaster"` behaves like the default;
- an invalid feed (`"feed": "ftp"`, and a non-string) returns 422 and records no heartbeat;
- dedup still holds across feeds: ingest the same bulletin once with `feed=byteblaster` and once with `feed=http` (different `path`s, same WMO header text) and assert exactly one `IngestedProduct` row exists.

Add to `tests/Feature/PipelineControlTest.php` (mirror the existing Docker `Http::fake` URLs for container status/start/stop/restart; the proxy base is `docker-socket-proxy:2375/v1.43/containers/<name>/...`):
- the page shows the EMWIN HTTP row with status from `wxnotify-emwin-http`;
- start is visible and stop hidden when it is not running, and the reverse when running (actions `startEmwinHttp`, `stopEmwinHttp`, `restartEmwinHttp`);
- each action calls the right Docker endpoint for `wxnotify-emwin-http`;
- "Last product" shows "never" with no heartbeat and a relative time after `PipelineHeartbeat::record('emwin_http')`;
- the old placeholder row (static "Status unknown" for "EMWIN HTTP backup") no longer renders.

If `ResetToFactoryDefaultsPageTest.php` asserts the checkbox label, update that assertion to the new label.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestEmwinProductControllerTest.php tests/Feature/PipelineControlTest.php --compact"`
Expected: FAIL (no `feed` handling, no EMWIN HTTP actions).

- [ ] **Step 3: Implement the controller change**

In `IngestEmwinProductController::__invoke`, extend the validation and pick the heartbeat key from `feed`:

```php
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'feed' => ['nullable', 'string', 'in:byteblaster,http'],
        ]);

        PipelineHeartbeat::record(($validated['feed'] ?? 'byteblaster') === 'http' ? 'emwin_http' : 'emwin');
```

Nothing else in the controller changes (path validation against the shared root, `source = emwin`, the `IngestOncePerWmoHeader` dedup all stay exactly as they are).

- [ ] **Step 4: Implement the Pipeline Control row**

In `PipelineControl.php` add `private const EMWIN_HTTP_CONTAINER = 'wxnotify-emwin-http';` and, mirroring the existing EMWIN Byteblaster members exactly: `emwinHttpStatus()`, `emwinHttpStatusColor()`, `emwinHttpStatusLabel()`, `emwinHttpLastProductAt()` (heartbeat key `emwin_http`), and the three actions `startEmwinHttpAction()`, `stopEmwinHttpAction()` (confirmation, modal text "This stops the EMWIN HTTP backup downloader until it is started again."), `restartEmwinHttpAction()` (confirmation), each calling `runDockerAction('EMWIN HTTP', self::EMWIN_HTTP_CONTAINER, ...)` through `DockerEngineClient`.

In `pipeline-control.blade.php` replace the placeholder `@foreach (['EMWIN HTTP backup'] as $client)` block with a real row copied from the EMWIN Byteblaster row (label "EMWIN HTTP backup", the status badge, the "Last product" text, and the start/stop/restart buttons using the new actions). Keep the NWWS and Byteblaster rows untouched.

In `ResetToFactoryDefaults.php` add "EMWIN HTTP" to the parenthesised list in the checkbox label: `(Downloader, EMWIN HTTP, Alert Engine, GLM, Threshold evaluator, Traccar poller)`.

- [ ] **Step 5: Run the tests to verify they pass, then the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestEmwinProductControllerTest.php tests/Feature/PipelineControlTest.php tests/Feature/ResetToFactoryDefaultsPageTest.php tests/Feature/FactoryResetActionsTest.php --compact"` then the full suite (`vendor/bin/pest --compact`, about two minutes; wait for it). Expected: all pass (report the total).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/IngestEmwinProductController.php app/Filament/Admin/Pages/PipelineControl.php app/Filament/Admin/Pages/ResetToFactoryDefaults.php resources/views/filament/admin/pages/pipeline-control.blade.php tests/Feature
git commit -m "$(cat <<'EOF'
Track the EMWIN HTTP feed separately and add its Pipeline Control row

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage**
- Config file with the six fields plus `server_path`, defaults, validation, refuse-to-start on invalid config, flags gating each kind → Task 1 (+ Task 3 `main`, Task 4 config.json).
- Cadence from the zip name (all six names in the spec), unrecognized name a config error, per-file loops, conditional requests, backoff capped at cadence → Tasks 1 and 3.
- Safe unzip with the exact limits and skip rules, atomic writes, discard-on-breach → Task 2 (limits from env in Task 1).
- Only unseen files posted, seen-set bounded at 50,000 and persisted atomically, validators recorded only after every post succeeded, restart-safe → Tasks 2 and 3 (`RunCycle`).
- Images downloaded and unzipped only, never posted, image loop absent when the flag is off → Task 3 (`RunCycle` with nil poster, `main` job list).
- Retention (7 days default) → Tasks 2 and 3. Healthcheck within three cadences (304 counts as success) → Task 3 (`health`, `main`) and the Dockerfile in Task 4.
- Container `wxnotify-emwin-http`, compose service, shared `emwin-products` volume, own state volume, logs to stdout, token/URL from env → Task 4.
- `feed` parameter and separate heartbeat, source still `emwin`, dedup unchanged, Pipeline Control row, factory-reset label → Task 5.
- Testing lists (Go and Pest) → present in Tasks 1-3 and 5.
- Out of scope (image ingest, Byteblaster changes, dedup-window fix) → not built.

**Placeholder scan:** none. Task 5's tests are specified by behavior, with explicit instructions to read the existing test files and mirror their helpers, because those fixtures are not reproduced here.

**Type/name consistency:** `config.Config/Load/Env/FromEnv`, `cadence.ForZip`, `unzip.Extract/Limits/ErrLimit`, `state.Store` methods, `fetch.Fetcher/Result/ErrTooLarge/StatusError`, `poster.Poster.Post`, `runner.Deps/RunCycle/Loop/Sleep/Fetcher/Poster`, `health.Mark/Check`, container name `wxnotify-emwin-http`, heartbeat key `emwin_http` are used identically across tasks.

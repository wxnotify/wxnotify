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
			"EMWIN_WEBHOOK_URL":      "http://laravel.test/api/pipeline/emwin-products",
			"PIPELINE_WEBHOOK_TOKEN": "t",
			"EMWIN_RETENTION_DAYS":   "3",
			"EMWIN_HTTP_MAX_MEMBERS": "10",
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

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

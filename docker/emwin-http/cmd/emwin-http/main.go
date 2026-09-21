// Command emwin-http polls NOAA's public EMWIN HTTP zip batches and feeds new products to the
// WxNotify alert pipeline. It is the backup to the Byteblaster feed.
package main

import (
	"context"
	"flag"
	"fmt"
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

	jobs := buildJobs(cfg, env)
	cadences := map[string]time.Duration{}
	names := []string{}
	for _, j := range jobs {
		cadences[j.Zip] = j.Cadence
		names = append(names, j.Zip)
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

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if len(jobs) == 0 {
		// Exiting would make `restart: unless-stopped` spin; idle instead (the healthcheck passes).
		log.Warn("both download_text and download_images are false; nothing to do")
		<-ctx.Done()
		return
	}

	if err := checkWritable(writableDirs(jobs, env)); err != nil {
		log.Error("startup writability check failed", "error", err.Error())
		os.Exit(1)
	}

	client := &http.Client{Timeout: 60 * time.Second}
	limits := unzip.Limits{MaxMemberBytes: env.MaxMemberBytes, MaxTotalBytes: env.MaxTotalBytes, MaxMembers: env.MaxMembers}
	maxAge := time.Duration(env.RetentionDays) * 24 * time.Hour

	var wg sync.WaitGroup
	for _, j := range jobs {
		store, err := state.Open(filepath.Join(env.StateDir, j.Zip+".json"), maxSeen)
		if err != nil {
			log.Error("open state", "zip", j.Zip, "error", err.Error())
			os.Exit(1)
		}
		if store.Recovered() {
			log.Warn("state file was corrupt; starting empty", "zip", j.Zip)
		}

		var p runner.Poster
		if j.Posts {
			p = &poster.Poster{Client: client, URL: env.WebhookURL, Token: env.WebhookToken}
		}

		deps := runner.Deps{
			Fetcher:    &fetch.Fetcher{Client: client, URL: cfg.URLFor(j.Zip), MaxBytes: env.MaxZipBytes},
			Poster:     p,
			Store:      store,
			Limits:     limits,
			StorageDir: j.StorageDir,
			RelPrefix:  j.RelPrefix,
			TempDir:    os.TempDir(),
			Log:        log.With("zip", j.Zip),
		}

		wg.Add(1)
		go func(zip string, cad time.Duration, deps runner.Deps, dir string) {
			defer wg.Done()
			runner.Loop(ctx, zip, cad,
				func(ctx context.Context) error {
					err := runner.RunCycle(ctx, deps)
					if n, cerr := retention.Cleanup(dir, maxAge, time.Now()); cerr != nil {
						deps.Log.Warn("retention cleanup failed", "error", cerr.Error())
					} else if n > 0 {
						deps.Log.Info("retention cleanup", "deleted", n)
					}
					return err
				},
				func() {
					if err := health.Mark(healthDir, zip, time.Now()); err != nil {
						deps.Log.Error("could not record health marker", "error", err.Error())
					}
				},
				runner.Sleep, deps.Log)
		}(j.Zip, j.Cadence, deps, j.StorageDir)
	}

	log.Info("emwin-http started", "text", cfg.DownloadText, "images", cfg.DownloadImages)
	wg.Wait()
}

// jobSpec describes one zip loop: what to fetch, where it lands and whether products are posted.
type jobSpec struct {
	Zip        string
	Cadence    time.Duration
	StorageDir string
	RelPrefix  string // empty when nothing is posted
	Posts      bool
	Name       string // health marker name
}

// buildJobs turns the config into the enabled zip loops (text first, then images).
func buildJobs(cfg config.Config, env config.Env) []jobSpec {
	var jobs []jobSpec
	if cfg.DownloadText {
		cad, _ := cadence.ForZip(cfg.TextZip)
		jobs = append(jobs, jobSpec{Zip: cfg.TextZip, Name: cfg.TextZip, Cadence: cad, StorageDir: cfg.StorageDir, RelPrefix: cfg.RelPrefix(), Posts: true})
	}
	if cfg.DownloadImages {
		cad, _ := cadence.ForZip(cfg.ImageZip)
		jobs = append(jobs, jobSpec{Zip: cfg.ImageZip, Name: cfg.ImageZip, Cadence: cad, StorageDir: cfg.ImagesDir()})
	}
	return jobs
}

// writableDirs lists every directory the enabled jobs need to write to.
func writableDirs(jobs []jobSpec, env config.Env) []string {
	dirs := []string{env.StateDir}
	for _, j := range jobs {
		dirs = append(dirs, j.StorageDir)
	}
	return dirs
}

// checkWritable creates each directory if needed and proves it accepts a file.
func checkWritable(dirs []string) error {
	for _, dir := range dirs {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return fmt.Errorf("%s is not usable: %w", dir, err)
		}
		f, err := os.CreateTemp(dir, ".writecheck-*")
		if err != nil {
			return fmt.Errorf("%s is not writable: %w", dir, err)
		}
		f.Close()
		if err := os.Remove(f.Name()); err != nil {
			return fmt.Errorf("%s is not writable: %w", dir, err)
		}
	}
	return nil
}

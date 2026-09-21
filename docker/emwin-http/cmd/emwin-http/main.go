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

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

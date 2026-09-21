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

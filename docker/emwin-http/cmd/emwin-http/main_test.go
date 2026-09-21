package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"wxnotify.local/emwin-http/internal/config"
)

func TestBuildJobsTextOnlyByDefault(t *testing.T) {
	jobs := buildJobs(config.Defaults(), config.Env{})
	if len(jobs) != 1 {
		t.Fatalf("jobs = %+v", jobs)
	}
	j := jobs[0]
	if j.Zip != "txtmin02.zip" || j.Cadence != 2*time.Minute || j.StorageDir != "/emwin-products/http" || j.RelPrefix != "http" || !j.Posts || j.Name != "txtmin02.zip" {
		t.Fatalf("text job = %+v", j)
	}
}

func TestBuildJobsAddsAnImageJobThatDoesNotPost(t *testing.T) {
	cfg := config.Defaults()
	cfg.DownloadImages = true
	jobs := buildJobs(cfg, config.Env{})
	if len(jobs) != 2 {
		t.Fatalf("jobs = %+v", jobs)
	}
	img := jobs[1]
	if img.Zip != "imgmin15.zip" || img.Cadence != 15*time.Minute || img.StorageDir != "/emwin-products/http-images" || img.RelPrefix != "" || img.Posts {
		t.Fatalf("image job = %+v", img)
	}
}

func TestBuildJobsUsesTheStorageDirPrefixAndCadencePerZip(t *testing.T) {
	cfg := config.Defaults()
	cfg.StorageDir = "/emwin-products/feeds/http"
	cfg.TextZip = "txtmin06.zip"
	jobs := buildJobs(cfg, config.Env{})
	if len(jobs) != 1 || jobs[0].RelPrefix != "feeds/http" || jobs[0].Cadence != 6*time.Minute {
		t.Fatalf("jobs = %+v", jobs)
	}
}

func TestBuildJobsBothOffMeansNoJobs(t *testing.T) {
	cfg := config.Defaults()
	cfg.DownloadText = false
	if jobs := buildJobs(cfg, config.Env{}); len(jobs) != 0 {
		t.Fatalf("jobs = %+v", jobs)
	}
}

func TestCheckWritableCreatesDirectoriesAndLeavesNoFiles(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "a", "b")
	if err := checkWritable([]string{dir}); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 0 {
		t.Fatalf("probe file left behind: %v", entries)
	}
}

func TestCheckWritableFailsForAnUnusablePath(t *testing.T) {
	file := filepath.Join(t.TempDir(), "afile")
	os.WriteFile(file, nil, 0o600)
	if err := checkWritable([]string{filepath.Join(file, "sub")}); err == nil {
		t.Fatal("expected an error for a path under a file")
	}
}

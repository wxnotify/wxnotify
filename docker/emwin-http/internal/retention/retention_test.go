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

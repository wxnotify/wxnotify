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

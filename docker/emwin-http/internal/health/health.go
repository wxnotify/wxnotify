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

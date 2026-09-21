package health

import (
	"testing"
	"time"
)

func TestCheckPassesWithFreshMarkers(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 9, 21, 12, 0, 0, 0, time.UTC)
	Mark(dir, "txtmin02.zip", now.Add(-4*time.Minute))

	err := Check(dir, []string{"txtmin02.zip"}, map[string]time.Duration{"txtmin02.zip": 2 * time.Minute}, now)
	if err != nil {
		t.Fatal(err)
	}
}

func TestCheckFailsWhenStaleOrMissing(t *testing.T) {
	dir := t.TempDir()
	now := time.Date(2026, 9, 21, 12, 0, 0, 0, time.UTC)
	cad := map[string]time.Duration{"txtmin02.zip": 2 * time.Minute, "imgmin15.zip": 15 * time.Minute}

	Mark(dir, "txtmin02.zip", now.Add(-7*time.Minute)) // older than 3 x 2 minutes

	if err := Check(dir, []string{"txtmin02.zip"}, cad, now); err == nil {
		t.Fatal("a stale marker should fail")
	}
	if err := Check(dir, []string{"imgmin15.zip"}, cad, now); err == nil {
		t.Fatal("a missing marker should fail")
	}
}

func TestCheckWithNothingEnabledPasses(t *testing.T) {
	if err := Check(t.TempDir(), nil, nil, time.Now()); err != nil {
		t.Fatal(err)
	}
}

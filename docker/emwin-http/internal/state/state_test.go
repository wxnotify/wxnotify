package state

import (
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

func TestRoundTripPersistsValidatorsAndSeen(t *testing.T) {
	path := filepath.Join(t.TempDir(), "s", "txtmin02.zip.json")

	s, err := Open(path, 100)
	if err != nil || s.Recovered() {
		t.Fatalf("open: %v recovered=%v", err, s.Recovered())
	}
	s.MarkSeen("a.txt")
	s.SetValidators("Mon, 21 Sep 2026 16:14:00 GMT", `"abc"`)
	if err := s.Save(); err != nil {
		t.Fatal(err)
	}

	again, err := Open(path, 100)
	if err != nil {
		t.Fatal(err)
	}
	lm, etag := again.Validators()
	if !again.Seen("a.txt") || again.Seen("b.txt") || lm != "Mon, 21 Sep 2026 16:14:00 GMT" || etag != `"abc"` {
		t.Fatalf("state not restored: seen=%v lm=%q etag=%q", again.Seen("a.txt"), lm, etag)
	}
}

func TestSeenSetIsBoundedOldestFirst(t *testing.T) {
	s, _ := Open(filepath.Join(t.TempDir(), "s.json"), 3)
	for i := 0; i < 5; i++ {
		s.MarkSeen(fmt.Sprintf("f%d", i))
	}
	if s.Seen("f0") || s.Seen("f1") || !s.Seen("f2") || !s.Seen("f4") {
		t.Fatal("oldest names should be evicted first")
	}
}

func TestMarkSeenIsIdempotent(t *testing.T) {
	s, _ := Open(filepath.Join(t.TempDir(), "s.json"), 2)
	s.MarkSeen("a")
	s.MarkSeen("a")
	s.MarkSeen("b")
	if !s.Seen("a") || !s.Seen("b") {
		t.Fatal("a repeated name must not evict others")
	}
}

func TestCorruptFileStartsEmptyAndIsFlagged(t *testing.T) {
	path := filepath.Join(t.TempDir(), "s.json")
	os.WriteFile(path, []byte("{not json"), 0o600)

	s, err := Open(path, 10)
	if err != nil || !s.Recovered() || s.Seen("x") {
		t.Fatalf("err=%v recovered=%v", err, s.Recovered())
	}
}

func TestSaveLeavesNoTempFiles(t *testing.T) {
	dir := t.TempDir()
	s, _ := Open(filepath.Join(dir, "s.json"), 10)
	s.MarkSeen("a")
	if err := s.Save(); err != nil {
		t.Fatal(err)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 || entries[0].Name() != "s.json" {
		t.Fatalf("unexpected directory contents: %v", entries)
	}
}

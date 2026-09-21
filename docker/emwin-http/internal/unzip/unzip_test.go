package unzip

import (
	"archive/zip"
	"bytes"
	"compress/flate"
	"errors"
	"hash/crc32"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

func makeZip(t *testing.T, files map[string]string) string {
	t.Helper()
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	for name, body := range files {
		f, err := w.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		f.Write([]byte(body))
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "in.zip")
	if err := os.WriteFile(path, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func all(string) bool { return true }

var generous = Limits{MaxMemberBytes: 1 << 20, MaxTotalBytes: 10 << 20, MaxMembers: 100}

func listDir(t *testing.T, dir string) []string {
	t.Helper()
	entries, _ := os.ReadDir(dir)
	names := []string{}
	for _, e := range entries {
		names = append(names, e.Name())
	}
	sort.Strings(names)
	return names
}

func TestExtractWritesWantedMembers(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{"a.txt": "one", "b.txt": "two"})

	written, skipped, err := Extract(zipPath, dest, generous, func(n string) bool { return n == "b.txt" })
	if err != nil || len(skipped) != 0 {
		t.Fatalf("err=%v skipped=%v", err, skipped)
	}
	sort.Strings(written)
	if len(written) != 1 || written[0] != "b.txt" {
		t.Fatalf("written = %v", written)
	}
	if got, _ := os.ReadFile(filepath.Join(dest, "b.txt")); string(got) != "two" {
		t.Fatalf("content = %q", got)
	}
	if _, err := os.Stat(filepath.Join(dest, "a.txt")); err == nil {
		t.Fatal("unwanted member was written")
	}
}

func TestExtractSkipsUnsafeNames(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{
		"../evil.txt": "x", "a/b.txt": "x", "/abs.txt": "x", `c\d.txt`: "x", "..": "x", ".hidden": "x", "good.txt": "ok",
	})

	written, skipped, err := Extract(zipPath, dest, generous, all)
	if err != nil {
		t.Fatal(err)
	}
	if len(written) != 1 || written[0] != "good.txt" || len(skipped) != 6 {
		t.Fatalf("written=%v skipped=%v", written, skipped)
	}
	if got := listDir(t, dest); len(got) != 1 || got[0] != "good.txt" {
		t.Fatalf("dest contents = %v", got)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(dest), "evil.txt")); err == nil {
		t.Fatal("path traversal escaped the destination")
	}
}

func TestExtractSanitisesNames(t *testing.T) {
	dest := t.TempDir()
	zipPath := makeZip(t, map[string]string{"weird name!.txt": "x"})

	written, _, err := Extract(zipPath, dest, generous, all)
	if err != nil || len(written) != 1 || written[0] != "weird_name_.txt" {
		t.Fatalf("written=%v err=%v", written, err)
	}
}

func TestExtractEnforcesLimitsAndLeavesNothing(t *testing.T) {
	cases := map[string]struct {
		files  map[string]string
		limits Limits
	}{
		"member too big": {map[string]string{"a.txt": strings.Repeat("x", 100)}, Limits{MaxMemberBytes: 10, MaxTotalBytes: 1 << 20, MaxMembers: 10}},
		"total too big":  {map[string]string{"a.txt": strings.Repeat("x", 60), "b.txt": strings.Repeat("y", 60)}, Limits{MaxMemberBytes: 100, MaxTotalBytes: 100, MaxMembers: 10}},
		"too many":       {map[string]string{"a.txt": "1", "b.txt": "2", "c.txt": "3"}, Limits{MaxMemberBytes: 100, MaxTotalBytes: 100, MaxMembers: 2}},
	}
	for name, tc := range cases {
		dest := t.TempDir()
		_, _, err := Extract(makeZip(t, tc.files), dest, tc.limits, all)
		if !errors.Is(err, ErrLimit) {
			t.Errorf("%s: err = %v, want ErrLimit", name, err)
		}
		if got := listDir(t, dest); len(got) != 0 {
			t.Errorf("%s: files left behind: %v", name, got)
		}
	}
}

func TestExtractRejectsANonZip(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bad.zip")
	os.WriteFile(path, []byte("not a zip"), 0o600)

	if _, _, err := Extract(path, t.TempDir(), generous, all); err == nil {
		t.Fatal("expected an error")
	}
}

func TestExtractEnforcesMemberLimitWhileCopyingWhenDeclaredSizeLies(t *testing.T) {
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)

	good, _ := w.Create("a-good.txt")
	good.Write([]byte("fine"))

	// Compress 1000 real bytes but declare only 5 in the header.
	var deflated bytes.Buffer
	fw, _ := flate.NewWriter(&deflated, flate.DefaultCompression)
	real := []byte(strings.Repeat("z", 1000))
	fw.Write(real)
	fw.Close()
	raw, err := w.CreateRaw(&zip.FileHeader{
		Name:               "b-liar.txt",
		Method:             zip.Deflate,
		CRC32:              crc32.ChecksumIEEE(real),
		CompressedSize64:   uint64(deflated.Len()),
		UncompressedSize64: 5,
	})
	if err != nil {
		t.Fatal(err)
	}
	raw.Write(deflated.Bytes())
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	zipPath := filepath.Join(t.TempDir(), "liar.zip")
	os.WriteFile(zipPath, buf.Bytes(), 0o600)

	dest := t.TempDir()
	_, _, err = Extract(zipPath, dest, Limits{MaxMemberBytes: 10, MaxTotalBytes: 1 << 20, MaxMembers: 10}, all)
	if err == nil {
		t.Fatal("expected an error")
	}
	// archive/zip itself stops at the declared size (zip: not a valid zip file); either way
	// the call must fail and leave nothing on disk.
	if got := listDir(t, dest); len(got) != 0 {
		t.Fatalf("files left behind (including temp files): %v", got)
	}
}

func TestExtractFirstOfCollidingSanitisedNamesWinsAndWantSeesSanitisedName(t *testing.T) {
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	first, _ := w.Create("a b.txt")
	first.Write([]byte("first"))
	second, _ := w.Create("a!b.txt")
	second.Write([]byte("second"))
	w.Close()
	zipPath := filepath.Join(t.TempDir(), "c.zip")
	os.WriteFile(zipPath, buf.Bytes(), 0o600)

	var asked []string
	dest := t.TempDir()
	written, skipped, err := Extract(zipPath, dest, generous, func(n string) bool { asked = append(asked, n); return true })
	if err != nil || len(written) != 1 || written[0] != "a_b.txt" || len(skipped) != 1 || skipped[0] != "a!b.txt" {
		t.Fatalf("written=%v skipped=%v err=%v", written, skipped, err)
	}
	if len(asked) != 1 || asked[0] != "a_b.txt" {
		t.Fatalf("want called with %v", asked)
	}
	if got, _ := os.ReadFile(filepath.Join(dest, "a_b.txt")); string(got) != "first" {
		t.Fatalf("content = %q", got)
	}
}

func TestExtractSkipsDirectoryAndSymlinkMembers(t *testing.T) {
	var buf bytes.Buffer
	w := zip.NewWriter(&buf)
	w.Create("dir/")
	link := &zip.FileHeader{Name: "link.txt"}
	link.SetMode(os.ModeSymlink | 0o777)
	lw, _ := w.CreateHeader(link)
	lw.Write([]byte("/etc/passwd"))
	w.Close()
	zipPath := filepath.Join(t.TempDir(), "d.zip")
	os.WriteFile(zipPath, buf.Bytes(), 0o600)

	dest := t.TempDir()
	written, skipped, err := Extract(zipPath, dest, generous, all)
	if err != nil || len(written) != 0 || len(skipped) != 2 || len(listDir(t, dest)) != 0 {
		t.Fatalf("written=%v skipped=%v err=%v", written, skipped, err)
	}
}

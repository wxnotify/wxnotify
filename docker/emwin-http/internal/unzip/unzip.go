// Package unzip extracts a zip into a flat directory, defensively.
package unzip

import (
	"archive/zip"
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// ErrLimit means the archive breached a configured size or count limit.
var ErrLimit = errors.New("zip exceeds the configured limits")

type Limits struct {
	MaxMemberBytes int64
	MaxTotalBytes  int64
	MaxMembers     int
}

var unsafeChars = regexp.MustCompile(`[^A-Za-z0-9._-]`)

// crRunBeforeLF matches the carriage returns that precede a line feed. EMWIN text ends its lines
// with CR CR LF, which reads as a blank line after every line.
var crRunBeforeLF = regexp.MustCompile("\r+\n")

// Option adjusts how Extract writes members.
type Option func(*options)

type options struct {
	normalizeLineEndings bool
}

// NormalizeLineEndings makes Extract rewrite every member's line endings to a single LF before
// the member is written, so a consumer never sees the doubled line breaks. Lone carriage returns
// in the middle of a line are kept. Only for text zips: never use it for images or other binary
// members.
func NormalizeLineEndings() Option {
	return func(o *options) { o.normalizeLineEndings = true }
}

func normalizeLineEndings(b []byte) []byte {
	return bytes.TrimRight(crRunBeforeLF.ReplaceAll(b, []byte("\n")), "\r")
}

// Extract writes the wanted, safe members of the zip into destDir (flat). want receives the
// sanitised name. Members with path separators or "..", directories, and empty or dotfile
// names are skipped and reported. Limits are checked over every member before anything is
// written and enforced again while copying; on any error nothing from this call remains.
func Extract(zipPath, destDir string, limits Limits, want func(name string) bool, opts ...Option) (written, skipped []string, err error) {
	var o options
	for _, opt := range opts {
		opt(&o)
	}

	r, err := zip.OpenReader(zipPath)
	// Members with unsafe names make OpenReader return the reader together with
	// zip.ErrInsecurePath; we skip those members ourselves, so carry on.
	if err != nil && !(r != nil && errors.Is(err, zip.ErrInsecurePath)) {
		return nil, nil, fmt.Errorf("open zip: %w", err)
	}
	defer r.Close()

	if len(r.File) > limits.MaxMembers {
		return nil, nil, fmt.Errorf("%w: %d members", ErrLimit, len(r.File))
	}

	maxMember := uint64(max(limits.MaxMemberBytes, 0))
	maxTotal := uint64(max(limits.MaxTotalBytes, 0))
	var total uint64
	for _, f := range r.File {
		if f.UncompressedSize64 > maxMember {
			return nil, nil, fmt.Errorf("%w: member %q is %d bytes", ErrLimit, f.Name, f.UncompressedSize64)
		}
		// total <= maxTotal and size <= maxMember (both <= MaxInt64), so this cannot overflow.
		total += f.UncompressedSize64
		if total > maxTotal {
			return nil, nil, fmt.Errorf("%w: total uncompressed size", ErrLimit)
		}
	}

	if err := os.MkdirAll(destDir, 0o755); err != nil {
		return nil, nil, fmt.Errorf("create destination: %w", err)
	}

	created := []string{}
	cleanup := func() {
		for _, p := range created {
			os.Remove(p)
		}
	}
	seenNames := map[string]bool{}

	for _, f := range r.File {
		name, ok := safeName(f)
		if !ok || seenNames[name] {
			skipped = append(skipped, f.Name)
			continue
		}
		seenNames[name] = true

		if !want(name) {
			continue
		}

		final := filepath.Join(destDir, name)
		if err := copyMember(f, final, limits.MaxMemberBytes, o.normalizeLineEndings); err != nil {
			cleanup()
			return nil, nil, err
		}
		created = append(created, final)
		written = append(written, name)
	}

	return written, skipped, nil
}

func safeName(f *zip.File) (string, bool) {
	if !f.Mode().IsRegular() || f.Name == "" {
		return "", false
	}
	if strings.ContainsAny(f.Name, `/\`) || strings.Contains(f.Name, "..") {
		return "", false
	}
	name := unsafeChars.ReplaceAllString(f.Name, "_")
	if name == "" || strings.HasPrefix(name, ".") {
		return "", false
	}
	return name, true
}

func copyMember(f *zip.File, final string, maxBytes int64, normalize bool) error {
	in, err := f.Open()
	if err != nil {
		return fmt.Errorf("open member %q: %w", f.Name, err)
	}
	defer in.Close()

	tmp, err := os.CreateTemp(filepath.Dir(final), ".tmp-*")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}
	tmpName := tmp.Name()

	var n int64
	var copyErr error
	if normalize {
		n, copyErr = writeNormalized(tmp, in, maxBytes)
	} else {
		n, copyErr = io.Copy(tmp, io.LimitReader(in, maxBytes+1))
	}
	closeErr := tmp.Close()

	switch {
	case copyErr != nil:
		os.Remove(tmpName)
		return fmt.Errorf("copy member %q: %w", f.Name, copyErr)
	case n > maxBytes:
		os.Remove(tmpName)
		return fmt.Errorf("%w: member %q grew past the limit while copying", ErrLimit, f.Name)
	case closeErr != nil:
		os.Remove(tmpName)
		return closeErr
	}

	if err := os.Chmod(tmpName, 0o644); err != nil {
		os.Remove(tmpName)
		return err
	}
	if err := os.Rename(tmpName, final); err != nil {
		os.Remove(tmpName)
		return err
	}
	return nil
}

// writeNormalized reads the whole member (bounded by maxBytes+1), and writes it with normalised
// line endings unless it breached the limit. It returns the raw size read, so the caller's limit
// check is judged on the bytes the zip really held, not the shrunken text.
func writeNormalized(w io.Writer, r io.Reader, maxBytes int64) (int64, error) {
	data, err := io.ReadAll(io.LimitReader(r, maxBytes+1))
	if err != nil {
		return int64(len(data)), err
	}
	if int64(len(data)) > maxBytes {
		return int64(len(data)), nil
	}
	_, err = w.Write(normalizeLineEndings(data))
	return int64(len(data)), err
}

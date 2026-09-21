// Package state persists, per zip file, the conditional-GET validators and a bounded set of
// product names already sent to the pipeline.
package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
)

type fileState struct {
	LastModified string   `json:"last_modified"`
	ETag         string   `json:"etag"`
	Seen         []string `json:"seen"`
}

type Store struct {
	mu        sync.Mutex
	path      string
	maxSeen   int
	st        fileState
	set       map[string]struct{}
	recovered bool
}

// Open loads the state file. A missing file is an empty state; a corrupt one is treated as
// empty and flagged via Recovered().
func Open(path string, maxSeen int) (*Store, error) {
	s := &Store{path: path, maxSeen: maxSeen, set: map[string]struct{}{}}

	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return s, nil
	}
	if err != nil {
		return nil, err
	}

	if err := json.Unmarshal(data, &s.st); err != nil {
		s.st = fileState{}
		s.recovered = true
		return s, nil
	}
	for _, name := range s.st.Seen {
		s.set[name] = struct{}{}
	}
	return s, nil
}

func (s *Store) Recovered() bool { return s.recovered }

func (s *Store) Seen(name string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, ok := s.set[name]
	return ok
}

// MarkSeen records a name, evicting the oldest names beyond the bound.
func (s *Store) MarkSeen(name string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, ok := s.set[name]; ok {
		return
	}
	s.set[name] = struct{}{}
	s.st.Seen = append(s.st.Seen, name)
	for len(s.st.Seen) > s.maxSeen {
		delete(s.set, s.st.Seen[0])
		s.st.Seen = s.st.Seen[1:]
	}
}

func (s *Store) Validators() (lastModified, etag string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.st.LastModified, s.st.ETag
}

func (s *Store) SetValidators(lastModified, etag string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.st.LastModified, s.st.ETag = lastModified, etag
}

// Save writes the state atomically (temp file + rename).
func (s *Store) Save() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	data, err := json.Marshal(s.st)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(s.path), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(s.path), ".state-*")
	if err != nil {
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		os.Remove(tmp.Name())
		return err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	if err := os.Rename(tmp.Name(), s.path); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	return nil
}

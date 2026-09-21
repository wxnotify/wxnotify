package fetch

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newFetcher(url string, max int64) *Fetcher {
	return &Fetcher{Client: http.DefaultClient, URL: url, MaxBytes: max}
}

func TestFetchStoresBodyAndValidators(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-Modified-Since") != "" || r.Header.Get("If-None-Match") != "" {
			t.Error("first request must be unconditional")
		}
		w.Header().Set("Last-Modified", "Mon, 21 Sep 2026 16:14:00 GMT")
		w.Header().Set("ETag", `"v1"`)
		w.Write([]byte("zipbytes"))
	}))
	defer srv.Close()

	dest := filepath.Join(t.TempDir(), "out.zip")
	res, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "", "", dest)
	if err != nil || res.NotModified || res.LastModified != "Mon, 21 Sep 2026 16:14:00 GMT" || res.ETag != `"v1"` {
		t.Fatalf("res=%+v err=%v", res, err)
	}
	if got, _ := os.ReadFile(dest); string(got) != "zipbytes" {
		t.Fatalf("body = %q", got)
	}
}

func TestFetchSendsValidatorsAndHandles304(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("If-Modified-Since") != "Mon, 21 Sep 2026 16:14:00 GMT" || r.Header.Get("If-None-Match") != `"v1"` {
			t.Errorf("validators not sent: %v", r.Header)
		}
		w.WriteHeader(http.StatusNotModified)
	}))
	defer srv.Close()

	res, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "Mon, 21 Sep 2026 16:14:00 GMT", `"v1"`, filepath.Join(t.TempDir(), "o.zip"))
	if err != nil || !res.NotModified {
		t.Fatalf("res=%+v err=%v", res, err)
	}
}

func TestFetchReportsHTTPErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()

	_, err := newFetcher(srv.URL, 1<<20).Fetch(context.Background(), "", "", filepath.Join(t.TempDir(), "o.zip"))
	var se *StatusError
	if !errors.As(err, &se) || se.Code != 503 {
		t.Fatalf("err = %v", err)
	}
}

func TestFetchEnforcesTheSizeCapAndLeavesNoFile(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(strings.Repeat("x", 200)))
	}))
	defer srv.Close()

	dest := filepath.Join(t.TempDir(), "o.zip")
	_, err := newFetcher(srv.URL, 100).Fetch(context.Background(), "", "", dest)
	if !errors.Is(err, ErrTooLarge) {
		t.Fatalf("err = %v", err)
	}
	if _, statErr := os.Stat(dest); statErr == nil {
		t.Fatal("partial file left behind")
	}
}

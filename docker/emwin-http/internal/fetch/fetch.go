// Package fetch downloads a zip with conditional-GET support.
package fetch

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
)

var ErrTooLarge = errors.New("download exceeds the size cap")

type StatusError struct{ Code int }

func (e *StatusError) Error() string { return fmt.Sprintf("unexpected HTTP status %d", e.Code) }

type Result struct {
	NotModified  bool
	LastModified string
	ETag         string
}

type Fetcher struct {
	Client   *http.Client
	URL      string
	MaxBytes int64
}

// sameHostHTTPS refuses redirects to another host or to a non-https URL, and caps the chain at 10.
func sameHostHTTPS(host string) func(*http.Request, []*http.Request) error {
	return func(req *http.Request, via []*http.Request) error {
		if len(via) >= 10 {
			return errors.New("stopped after 10 redirects")
		}
		if req.URL.Scheme != "https" {
			return fmt.Errorf("refusing redirect to non-https URL scheme %q", req.URL.Scheme)
		}
		if req.URL.Host != host {
			return fmt.Errorf("refusing redirect to a different host %q", req.URL.Host)
		}
		return nil
	}
}

// Fetch downloads the file to dest. When lastModified or etag are set they are sent as
// conditional headers; a 304 returns NotModified without touching dest. A body larger than
// MaxBytes fails with ErrTooLarge and leaves no file behind.
func (f *Fetcher) Fetch(ctx context.Context, lastModified, etag, dest string) (Result, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, f.URL, nil)
	if err != nil {
		return Result{}, err
	}
	req.Header.Set("User-Agent", "wxnotify-emwin-http")
	if lastModified != "" {
		req.Header.Set("If-Modified-Since", lastModified)
	}
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}

	client := *f.Client
	client.CheckRedirect = sameHostHTTPS(req.URL.Host)
	resp, err := client.Do(req)
	if err != nil {
		return Result{}, err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusNotModified:
		return Result{NotModified: true}, nil
	case http.StatusOK:
	default:
		return Result{}, &StatusError{Code: resp.StatusCode}
	}

	out, err := os.Create(dest)
	if err != nil {
		return Result{}, err
	}

	n, copyErr := io.Copy(out, io.LimitReader(resp.Body, f.MaxBytes+1))
	closeErr := out.Close()

	if copyErr != nil || closeErr != nil || n > f.MaxBytes {
		os.Remove(dest)
		switch {
		case n > f.MaxBytes:
			return Result{}, ErrTooLarge
		case copyErr != nil:
			return Result{}, copyErr
		default:
			return Result{}, closeErr
		}
	}

	return Result{LastModified: resp.Header.Get("Last-Modified"), ETag: resp.Header.Get("ETag")}, nil
}

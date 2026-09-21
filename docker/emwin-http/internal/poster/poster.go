// Package poster tells the Laravel alert pipeline about a new product file.
package poster

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
)

// StatusError is a non-2xx answer from the pipeline. It carries no token and no response body.
type StatusError struct {
	Code int
	Path string
}

func (e *StatusError) Error() string {
	return fmt.Sprintf("post %q: pipeline answered HTTP %d", e.Path, e.Code)
}

type Poster struct {
	Client *http.Client
	URL    string
	Token  string
}

// Post sends the product's path (relative to the shared products root) to the pipeline.
// Any 2xx is success; the error never includes the token.
func (p *Poster) Post(ctx context.Context, relPath string) error {
	body, err := json.Marshal(map[string]string{"path": relPath, "feed": "http"})
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, p.URL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+p.Token)

	resp, err := p.Client.Do(req)
	if err != nil {
		return fmt.Errorf("post %q: %w", relPath, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return &StatusError{Code: resp.StatusCode, Path: relPath}
	}
	return nil
}

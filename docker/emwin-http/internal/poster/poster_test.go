package poster

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestPostSendsPathFeedAndBearer(t *testing.T) {
	var got map[string]string
	var auth string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		body, _ := io.ReadAll(r.Body)
		json.Unmarshal(body, &got)
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	p := &Poster{Client: http.DefaultClient, URL: srv.URL, Token: "sekret"}
	if err := p.Post(context.Background(), "http/A_FOO.TXT"); err != nil {
		t.Fatal(err)
	}
	if auth != "Bearer sekret" || got["path"] != "http/A_FOO.TXT" || got["feed"] != "http" {
		t.Fatalf("auth=%q body=%v", auth, got)
	}
}

func TestPostFailsOnNon2xxWithoutLeakingTheToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	err := (&Poster{Client: http.DefaultClient, URL: srv.URL, Token: "sekret"}).Post(context.Background(), "http/x")
	if err == nil || strings.Contains(err.Error(), "sekret") || !strings.Contains(err.Error(), "500") {
		t.Fatalf("err = %v", err)
	}
}

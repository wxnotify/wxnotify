package poster

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
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

func TestPostReturnsTypedStatusErrorWithoutToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnprocessableEntity)
		w.Write([]byte("sekret in body"))
	}))
	defer srv.Close()

	err := (&Poster{Client: http.DefaultClient, URL: srv.URL, Token: "sekret"}).Post(context.Background(), "http/x")
	var se *StatusError
	if !errors.As(err, &se) || se.Code != 422 || se.Path != "http/x" {
		t.Fatalf("err = %v", err)
	}
	if strings.Contains(err.Error(), "sekret") {
		t.Fatalf("leaked: %v", err)
	}
}

func TestPostDrainsTheBodySoConnectionsAreReused(t *testing.T) {
	newConns := 0
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte(strings.Repeat("e", 4096)))
	}))
	srv.Config.ConnState = func(_ net.Conn, s http.ConnState) {
		if s == http.StateNew {
			newConns++
		}
	}
	srv.Start()
	defer srv.Close()

	p := &Poster{Client: &http.Client{Transport: &http.Transport{}}, URL: srv.URL, Token: "sekret"}
	for i := 0; i < 3; i++ {
		err := p.Post(context.Background(), "http/x")
		if err == nil || strings.Contains(err.Error(), "eeee") || strings.Contains(err.Error(), "sekret") {
			t.Fatalf("err = %v", err)
		}
	}
	if newConns != 1 {
		t.Fatalf("connections opened = %d, want 1 (reused)", newConns)
	}
}

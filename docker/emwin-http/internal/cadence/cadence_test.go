package cadence

import (
	"testing"
	"time"
)

func TestForZip(t *testing.T) {
	cases := map[string]time.Duration{
		"txtmin02.zip": 2 * time.Minute,
		"txtmin06.zip": 6 * time.Minute,
		"txtmin20.zip": 20 * time.Minute,
		"imgmin15.zip": 15 * time.Minute,
		"txthrs01.zip": time.Hour,
		"txthrs03.zip": 3 * time.Hour,
		"imghrs01.zip": time.Hour,
		"imghrs03.zip": 3 * time.Hour,
	}
	for name, want := range cases {
		got, err := ForZip(name)
		if err != nil || got != want {
			t.Errorf("ForZip(%q) = %v, %v; want %v", name, got, err, want)
		}
	}
}

func TestForZipRejectsUnknownNames(t *testing.T) {
	for _, name := range []string{"", "foo.zip", "txtmin00.zip", "txtmin2.zip", "txtmin02.tar", "../txtmin02.zip", "TXTMIN02.zip"} {
		if _, err := ForZip(name); err == nil {
			t.Errorf("ForZip(%q) should fail", name)
		}
	}
}

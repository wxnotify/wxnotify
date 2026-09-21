// Package cadence derives a zip file's update interval from its name.
package cadence

import (
	"fmt"
	"regexp"
	"strconv"
	"time"
)

var pattern = regexp.MustCompile(`^(?:txt|img)(min|hrs)(\d{2})\.zip$`)

// ForZip returns the update cadence encoded in an EMWIN zip file name:
// txtmin02.zip is every 2 minutes, imgmin15.zip every 15, txthrs03.zip every 3 hours.
func ForZip(name string) (time.Duration, error) {
	m := pattern.FindStringSubmatch(name)
	if m == nil {
		return 0, fmt.Errorf("unrecognised EMWIN zip file name %q", name)
	}

	n, _ := strconv.Atoi(m[2])
	if n == 0 {
		return 0, fmt.Errorf("zip file name %q has a zero cadence", name)
	}

	unit := time.Minute
	if m[1] == "hrs" {
		unit = time.Hour
	}

	return time.Duration(n) * unit, nil
}

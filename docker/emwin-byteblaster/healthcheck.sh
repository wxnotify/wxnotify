#!/bin/sh
pgrep -f 'npemwind -F' >/dev/null && curl -fsS -o /dev/null --max-time 3 http://localhost:8016/

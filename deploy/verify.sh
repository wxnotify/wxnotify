#!/usr/bin/env bash
# Checks the published compose files without starting anything:
#   deploy/single-server/compose.yaml and deploy/multi-server/compose.yaml.
# Needs docker compose v2 (2.24+) and jq. Prints one PASS/FAIL line per assertion
# and exits non-zero if any FAIL.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP="$(mktemp -d)"
cleanup() {
    if [ -n "${TMP:-}" ] && [ -d "$TMP" ]; then
        rm -r -- "$TMP"
    fi
}
trap cleanup EXIT

FAILED=0
pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; FAILED=1; }
check() { # check "description" command...
    local description="$1"
    shift
    if "$@" >/dev/null 2>&1; then pass "$description"; else fail "$description"; fi
}

for tool in docker jq; do
    command -v "$tool" >/dev/null 2>&1 || { echo "FAIL  $tool is required"; exit 1; }
done

# The temporary .env is the example, untouched, exactly as a self-hoster copies it.
prepare() { # prepare <single-server|multi-server>
    mkdir -p "$TMP/$1"
    cp "$HERE/$1/.env.example" "$TMP/$1/.env"
    cp "$HERE/$1/compose.yaml" "$TMP/$1/compose.yaml"
}

dc() { # dc <dir> <compose args...>
    local dir="$1"
    shift
    COMPOSE_PROFILES= docker compose --project-name verify --project-directory "$TMP/$dir" --file "$TMP/$dir/compose.yaml" "$@"
}

WORKERS="queue queue-long queue-heartbeat scheduler reverb"

for dir in single-server multi-server; do
    echo "== $dir"
    if [ ! -f "$HERE/$dir/compose.yaml" ] || [ ! -f "$HERE/$dir/.env.example" ]; then
        fail "$dir: compose.yaml and .env.example exist"
        continue
    fi
    pass "$dir: compose.yaml and .env.example exist"
    prepare "$dir"

    check "$dir: docker compose config -q succeeds (all profiles)" dc "$dir" --profile '*' config -q

    # Every WxNotify image line is tagged with the version.
    bad_images="$(grep -E '^[[:space:]]*image:.*WXNOTIFY_REGISTRY' "$HERE/$dir/compose.yaml" | grep -vc 'WXNOTIFY_VERSION' || true)"
    total_images="$(grep -cE '^[[:space:]]*image:.*WXNOTIFY_REGISTRY' "$HERE/$dir/compose.yaml" || true)"
    if [ "${total_images:-0}" -gt 0 ] && [ "${bad_images:-0}" -eq 0 ]; then
        pass "$dir: all $total_images wxnotify image lines use WXNOTIFY_VERSION"
    else
        fail "$dir: every wxnotify image line must use WXNOTIFY_VERSION ($total_images found, $bad_images without)"
    fi

    # A missing WXNOTIFY_VERSION is a clear error, not an empty tag.
    grep -v '^WXNOTIFY_VERSION=' "$TMP/$dir/.env" >"$TMP/$dir/.env.noversion" || true
    noversion_out="$(COMPOSE_PROFILES= docker compose --project-directory "$TMP/$dir" --file "$TMP/$dir/compose.yaml" --env-file "$TMP/$dir/.env.noversion" --profile '*' config -q 2>&1 || true)"
    if grep -q 'WXNOTIFY_VERSION' <<<"$noversion_out"; then
        pass "$dir: a missing WXNOTIFY_VERSION fails with a message naming it"
    else
        fail "$dir: a missing WXNOTIFY_VERSION must fail with a message naming it"
    fi

    # No build sections, no dev bind mounts.
    if grep -Eq '^[[:space:]]*build:|\./docker/|\.:/var/www/html' "$HERE/$dir/compose.yaml"; then
        fail "$dir: no build: sections or repository bind mounts"
    else
        pass "$dir: no build: sections or repository bind mounts"
    fi

    # AUTORUN is set only where intended.
    if [ "$dir" = single-server ]; then
        autorun="$(dc "$dir" --profile '*' config --format json | jq -r '[.services | to_entries[] | select(.value.environment.AUTORUN_ENABLED != null) | .key] | sort | join(",")')"
        if [ "$autorun" = "front-end" ]; then
            pass "$dir: only front-end sets AUTORUN_ENABLED"
        else
            fail "$dir: only front-end may set AUTORUN_ENABLED (got: ${autorun:-none})"
        fi
        isolation="$(dc "$dir" config --format json | jq -r '.services["front-end"].environment | "\(.AUTORUN_ENABLED) \(.AUTORUN_LARAVEL_MIGRATION_ISOLATION)"')"
        if [ "$isolation" = "true true" ]; then
            pass "$dir: front-end sets AUTORUN_ENABLED and AUTORUN_LARAVEL_MIGRATION_ISOLATION to true"
        else
            fail "$dir: front-end must set both AUTORUN variables to true (got: $isolation)"
        fi
    else
        if grep -q 'AUTORUN' "$HERE/$dir/compose.yaml" "$HERE/$dir/.env.example"; then
            fail "$dir: AUTORUN must appear nowhere"
        else
            pass "$dir: AUTORUN appears nowhere"
        fi
    fi

    # Every worker has its own healthcheck, restart policy and stop signal.
    json="$(dc "$dir" --profile '*' config --format json)"
    for worker in $WORKERS; do
        ok="$(jq -r --arg w "$worker" '.services[$w] | (.healthcheck.test | type == "array" and (.[0] == "CMD") and (.[1] | startswith("healthcheck-"))) and .restart == "unless-stopped" and .stop_signal == "SIGTERM"' <<<"$json")"
        if [ "$ok" = true ]; then
            pass "$dir: $worker has a healthcheck-* test, restart and stop_signal"
        else
            fail "$dir: $worker needs a healthcheck-* test, restart: unless-stopped and stop_signal: SIGTERM"
        fi
    done
    for pair in queue:70s queue-long:930s; do
        svc="${pair%%:*}"
        want="${pair##*:}"
        got="$(jq -r --arg w "$svc" '.services[$w].stop_grace_period' <<<"$json")"
        # Compose prints 1m10s / 15m30s.
        case "$want:$got" in
            70s:1m10s | 930s:15m30s) pass "$dir: $svc stop_grace_period is $want" ;;
            *) fail "$dir: $svc stop_grace_period should be $want (got $got)" ;;
        esac
    done

    # Container names the dashboard's Docker control relies on.
    names="$(jq -r '[.services[] | .container_name // empty] | sort | join(",")' <<<"$json")"
    for required in wxnotify-nwws wxnotify-emwin-byteblaster wxnotify-emwin-http; do
        if [[ ",$names," == *",$required,"* ]]; then
            pass "$dir: container name $required"
        else
            fail "$dir: container name $required missing"
        fi
    done

    # Every ${VAR} the compose file references is listed in its .env.example
    # (commented-out optional settings count).
    missing=""
    while read -r var; do
        if ! grep -Eq "^#? ?${var}=" "$HERE/$dir/.env.example"; then
            missing="$missing $var"
        fi
    done < <(grep -o '\${[A-Za-z_][A-Za-z0-9_]*' "$HERE/$dir/compose.yaml" | sed 's/^\${//' | sort -u)
    if [ -z "$missing" ]; then
        pass "$dir: every \${VAR} is listed in .env.example"
    else
        fail "$dir: variables missing from .env.example:$missing"
    fi
done

echo "== single-server: ordering"
if [ -f "$HERE/single-server/compose.yaml" ]; then
    json="$(dc single-server --profile '*' config --format json 2>/dev/null || true)"
    for worker in $WORKERS pipeline-api; do
        cond="$(jq -r --arg w "$worker" '.services[$w].depends_on["front-end"].condition // "none"' <<<"$json" 2>/dev/null || echo none)"
        if [ "$cond" = service_healthy ]; then
            pass "single-server: $worker waits for front-end (service_healthy)"
        else
            fail "single-server: $worker must wait for front-end (condition: service_healthy), got $cond"
        fi
    done
    cond="$(jq -r '.services["front-end"].depends_on.pgsql.condition // "none"' <<<"$json" 2>/dev/null || echo none)"
    if [ "$cond" = service_healthy ]; then
        pass "single-server: front-end waits for pgsql (service_healthy)"
    else
        fail "single-server: front-end must wait for pgsql (service_healthy), got $cond"
    fi
    if jq -e '.services | has("redis") | not' <<<"$json" >/dev/null 2>&1; then
        pass "single-server: no redis service"
    else
        fail "single-server: redis must be omitted"
    fi
    if jq -e '.services | has("mailpit") | not' <<<"$json" >/dev/null 2>&1; then
        pass "single-server: no mailpit service"
    else
        fail "single-server: mailpit must be omitted"
    fi
else
    fail "single-server: compose.yaml missing"
fi

echo "== multi-server: profiles"
expected_for() {
    case "$1" in
        server-a) echo "front-end reverb" ;;
        server-b) echo "pgsql redis" ;;
        server-c) echo "docker-socket-proxy emwin-byteblaster emwin-http glm-decoder nwws pipeline-api queue-long" ;;
        server-d) echo "apprise queue queue-heartbeat scheduler" ;;
    esac
}
for profile in server-a server-b server-c server-d; do
    want="$(expected_for "$profile" | tr ' ' '\n' | sort | paste -sd, -)"
    if [ -f "$HERE/multi-server/compose.yaml" ]; then
        got="$(dc multi-server --profile "$profile" config --services 2>/dev/null | sort | paste -sd, - || true)"
    else
        got=""
    fi
    if [ "$got" = "$want" ]; then
        pass "multi-server: $profile runs exactly $want"
    else
        fail "multi-server: $profile should run $want (got: ${got:-nothing})"
    fi
done

if [ "$FAILED" -ne 0 ]; then
    echo "verify.sh: FAILED"
    exit 1
fi
echo "verify.sh: all assertions passed"

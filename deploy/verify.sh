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

SECRETS="APP_KEY DB_PASSWORD REVERB_APP_KEY REVERB_APP_SECRET PIPELINE_WEBHOOK_TOKEN"

# The temporary .env is the example with the (deliberately blank) secrets filled in, as a
# self-hoster would after generating them.
prepare() { # prepare <single-server|multi-server>
    mkdir -p "$TMP/$1"
    # WXNOTIFY_VERSION ships blank (the owner pins it); fill it in the temporary copy.
    sed 's/^WXNOTIFY_VERSION=.*/WXNOTIFY_VERSION=1.0.0/' "$HERE/$1/.env.example" >"$TMP/$1/.env"
    cp "$HERE/$1/compose.yaml" "$TMP/$1/compose.yaml"
    local secret
    for secret in $SECRETS; do
        sed "s/^${secret}=.*/${secret}=verify-${secret}/" "$TMP/$1/.env" >"$TMP/$1/.env.tmp"
        mv "$TMP/$1/.env.tmp" "$TMP/$1/.env"
    done
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

    # The example ships no secret values and no guessable placeholders.
    for secret in $SECRETS; do
        if grep -q "^${secret}=$" "$HERE/$dir/.env.example"; then
            pass "$dir: .env.example leaves $secret blank"
        else
            fail "$dir: .env.example must leave $secret blank"
        fi
    done
    for var in WXNOTIFY_VERSION TURNSTILE_SITE_KEY TURNSTILE_SECRET_KEY; do
        if grep -q "^${var}=$" "$HERE/$dir/.env.example"; then
            pass "$dir: .env.example leaves $var blank"
        else
            fail "$dir: .env.example must leave $var blank"
        fi
    done
    if grep -Eiq 'change-?me|changeit|password123|secret123' "$HERE/$dir/.env.example"; then
        fail "$dir: .env.example contains a placeholder-style value"
    else
        pass "$dir: .env.example contains no placeholder-style values"
    fi
    if [ "$dir" = single-server ]; then
        # Every secret is required: leaving it blank stops compose, naming the variable.
        for secret in $SECRETS; do
            sed "s/^${secret}=.*/${secret}=/" "$TMP/$dir/.env" >"$TMP/$dir/.env.blank"
            blank_out="$(COMPOSE_PROFILES= docker compose --project-directory "$TMP/$dir" --file "$TMP/$dir/compose.yaml" --env-file "$TMP/$dir/.env.blank" --profile '*' config -q 2>&1 || true)"
            if grep -q "set ${secret} in .env" <<<"$blank_out"; then
                pass "$dir: a blank $secret fails with a message naming it"
            else
                fail "$dir: a blank $secret must fail with a message naming it"
            fi
        done
    else
        blank_out="$(sed 's/^DB_PASSWORD=.*/DB_PASSWORD=/' "$TMP/$dir/.env" >"$TMP/$dir/.env.blank"; COMPOSE_PROFILES= docker compose --project-directory "$TMP/$dir" --file "$TMP/$dir/compose.yaml" --env-file "$TMP/$dir/.env.blank" --profile '*' config -q 2>&1 || true)"
        if grep -q 'set DB_PASSWORD in .env' <<<"$blank_out"; then
            pass "$dir: a blank DB_PASSWORD fails with a message naming it"
        else
            fail "$dir: a blank DB_PASSWORD must fail with a message naming it"
        fi
    fi

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
        # Isolation must stay off: `migrate --isolated` takes its lock in the database
        # cache's cache_locks table, which does not exist before the first migration.
        isolation="$(dc "$dir" config --format json | jq -r '.services["front-end"].environment | "\(.AUTORUN_ENABLED) \(.AUTORUN_LARAVEL_MIGRATION_ISOLATION)"')"
        if [ "$isolation" = "true null" ]; then
            pass "$dir: front-end sets AUTORUN_ENABLED to true and leaves AUTORUN_LARAVEL_MIGRATION_ISOLATION unset"
        else
            fail "$dir: front-end must set AUTORUN_ENABLED=true and leave AUTORUN_LARAVEL_MIGRATION_ISOLATION unset (got: $isolation)"
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

    # front-end keeps uploads (service-desk attachments, bug screenshots, onboarding files)
    # on a named volume, so recreating the container on an upgrade does not delete them.
    if jq -e '[.services["front-end"].volumes[]? | select(.type == "volume" and .target == "/var/www/html/storage")] | length == 1' <<<"$json" >/dev/null 2>&1; then
        pass "$dir: front-end mounts a named volume at /var/www/html/storage"
    else
        fail "$dir: front-end must mount a named volume at /var/www/html/storage"
    fi

    # Self-hosters run in selfhost mode; HTTPS deployments need the secure-cookie setting.
    if grep -q '^WXNOTIFY_MODE=selfhost$' "$HERE/$dir/.env.example"; then
        pass "$dir: .env.example sets WXNOTIFY_MODE=selfhost"
    else
        fail "$dir: .env.example must set WXNOTIFY_MODE=selfhost"
    fi
    if grep -Eq '^#? ?SESSION_SECURE_COOKIE=' "$HERE/$dir/.env.example"; then
        pass "$dir: .env.example lists SESSION_SECURE_COOKIE"
    else
        fail "$dir: .env.example must list SESSION_SECURE_COOKIE"
    fi

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
    start="$(jq -r '.services["front-end"].healthcheck.start_period // "none"' <<<"$json" 2>/dev/null || echo none)"
    seconds="$(awk -v d="$start" 'BEGIN{t=0; while (match(d, /^[0-9.]+[hms]/)) {n=substr(d,1,RLENGTH-1)+0; u=substr(d,RLENGTH,1); t+= (u=="h"?n*3600:(u=="m"?n*60:n)); d=substr(d,RLENGTH+1)} print t}')"
    if [ "${seconds:-0}" -ge 300 ]; then
        pass "single-server: front-end start_period is $start (>= 300s)"
    else
        fail "single-server: front-end start_period must be >= 300s (got $start)"
    fi
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
    if jq -e '.services | has("mailpit")' <<<"$json" >/dev/null 2>&1; then
        pass "single-server: mailpit service is present"
    else
        fail "single-server: mailpit service must be present (the default mailer)"
    fi
    if jq -e '[.services.mailpit.ports[]? | select(.target == 1025)] | length == 0' <<<"$json" >/dev/null 2>&1; then
        pass "single-server: mailpit does not publish SMTP (1025)"
    else
        fail "single-server: mailpit must not publish SMTP (1025)"
    fi
    got="$(jq -r '.services.mailpit.ports[] | select(.target == 8025) | "\(.host_ip // "")|\(.published)"' <<<"$json" 2>/dev/null || true)"
    if [ "$got" = "127.0.0.1|8025" ]; then
        pass "single-server: mailpit page publishes on 127.0.0.1 only by default"
    else
        fail "single-server: mailpit page must publish on 127.0.0.1:8025 by default (got: ${got:-nothing})"
    fi
    if grep -q '^MAIL_MAILER=mailpit$' "$HERE/single-server/.env.example"; then
        pass "single-server: .env.example defaults MAIL_MAILER to mailpit"
    else
        fail "single-server: .env.example must default MAIL_MAILER to mailpit"
    fi
else
    fail "single-server: compose.yaml missing"
fi

echo "== single-server: published ports"
if [ -f "$HERE/single-server/compose.yaml" ]; then
    # host_ip of the port that maps to container port 8080: <service> [env-file]
    host_ip() {
        local args=(--profile '*' config --format json)
        if [ -n "${2:-}" ]; then args=(--env-file "$2" "${args[@]}"); fi
        dc single-server "${args[@]}" 2>/dev/null | jq -r --arg s "$1" '.services[$s].ports[] | select(.target == 8080) | "\(.host_ip // "")|\(.published)"'
    }
    for pair in front-end:8080 reverb:8085; do
        svc="${pair%%:*}"
        port="${pair##*:}"
        got="$(host_ip "$svc" || true)"
        case "$got" in
            "|$port" | "0.0.0.0|$port") pass "single-server: $svc publishes $port on every interface by default" ;;
            *) fail "single-server: $svc should publish $port on every interface by default (got: ${got:-nothing})" ;;
        esac
    done
    { cat "$TMP/single-server/.env"; echo 'APP_BIND_ADDRESS=127.0.0.1'; echo 'REVERB_BIND_ADDRESS=127.0.0.1'; } >"$TMP/single-server/.env.loopback"
    for pair in front-end:8080 reverb:8085; do
        svc="${pair%%:*}"
        port="${pair##*:}"
        got="$(host_ip "$svc" "$TMP/single-server/.env.loopback" || true)"
        if [ "$got" = "127.0.0.1|$port" ]; then
            pass "single-server: $svc publishes on 127.0.0.1 when its bind address is set"
        else
            fail "single-server: $svc should publish on 127.0.0.1:$port when its bind address is set (got: ${got:-nothing})"
        fi
    done
fi

echo "== multi-server: database and redis host ports"
if [ -f "$HERE/multi-server/compose.yaml" ]; then
    { cat "$TMP/multi-server/.env"; echo 'FORWARD_DB_PORT=15432'; echo 'FORWARD_REDIS_PORT=16379'; } >"$TMP/multi-server/.env.ports"
    for spec in "pgsql:5432:5432:" "redis:6379:6379:" "pgsql:5432:15432:$TMP/multi-server/.env.ports" "redis:6379:16379:$TMP/multi-server/.env.ports"; do
        IFS=: read -r svc target want envfile <<<"$spec"
        args=(--profile server-b config --format json)
        if [ -n "$envfile" ]; then args=(--env-file "$envfile" "${args[@]}"); fi
        got="$(dc multi-server "${args[@]}" 2>/dev/null | jq -r --arg s "$svc" --argjson t "$target" '.services[$s].ports[] | select(.target == $t) | .published' || true)"
        if [ "$got" = "$want" ]; then
            pass "multi-server: $svc host port is $want${envfile:+ when FORWARD_* is set}"
        else
            fail "multi-server: $svc host port should be $want${envfile:+ when FORWARD_* is set} (got: ${got:-nothing})"
        fi
    done
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

echo "== documentation"
README="$HERE/README.md"
ROOT_README="$HERE/../README.md"
if [ ! -f "$README" ]; then
    fail "deploy/README.md exists"
else
    pass "deploy/README.md exists"

    # Every variable named inside a fenced block (NAME= or $NAME / ${NAME}) belongs to an
    # .env.example, unless it is a shell or Compose name on the allow-list.
    # POSTGRES_USER / POSTGRES_DB are read inside the pgsql container, not from .env.
    ALLOW=" PATH HOME PWD USER POSTGRES_USER POSTGRES_DB "
    fenced="$(awk '/^```/ { infence = !infence; next } infence { print }' "$README")"
    used="$( { grep -Eo '(^|[^A-Za-z0-9_])[A-Z][A-Z0-9_]{3,}=' <<<"$fenced" || true; grep -Eo '\$\{?[A-Z][A-Z0-9_]{3,}' <<<"$fenced" || true; } | sed -E 's/^[^A-Z]*//; s/^\$\{?//; s/=$//' | sort -u)"
    unknown=""
    for var in $used; do
        case "$ALLOW" in *" $var "*) continue ;; esac
        if ! grep -Eqh "^#? ?${var}=" "$HERE/single-server/.env.example" "$HERE/multi-server/.env.example"; then
            unknown="$unknown $var"
        fi
    done
    if [ -z "$unknown" ]; then
        pass "deploy/README.md: every variable in a command block is in an .env.example"
    else
        fail "deploy/README.md: variables not in any .env.example:$unknown"
    fi

    for term in WXNOTIFY_VERSION OUTLOOK_MAPS_DISK REVERB_PUBLIC_SCHEME REVERB_PUBLIC_PORT app:grant-owner Setup queue:restart reverb:restart Turnstile verification WXNOTIFY_TRUSTED_PROXIES SESSION_SECURE_COOKIE APP_BIND_ADDRESS REVERB_BIND_ADDRESS WXNOTIFY_MODE "Tier override" app-storage view:clear; do
        if grep -Fq -- "$term" "$README"; then
            pass "deploy/README.md mentions $term"
        else
            fail "deploy/README.md must mention $term"
        fi
    done
fi

# Every relative link points at a file that exists.
for doc in "$README" "$ROOT_README"; do
    if [ "$doc" = "$README" ]; then label="deploy/README.md"; else label="README.md"; fi
    if [ ! -f "$doc" ]; then
        fail "$label exists"
        continue
    fi
    broken=""
    while read -r target; do
        target="${target%%#*}"
        [ -z "$target" ] && continue
        if [ ! -e "$(dirname "$doc")/$target" ]; then
            broken="$broken $target"
        fi
    done < <(grep -Eo '\]\([^)]+\)' "$doc" | sed -E 's/^\]\(//; s/\)$//' | grep -Ev '^(https?:|mailto:|#|<)' || true)
    if [ -z "$broken" ]; then
        pass "$label: every relative link points at an existing file"
    else
        fail "$label: broken relative links:$broken"
    fi
done

if [ "$FAILED" -ne 0 ]; then
    echo "verify.sh: FAILED"
    exit 1
fi
echo "verify.sh: all assertions passed"

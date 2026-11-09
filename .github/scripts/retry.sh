#!/usr/bin/env bash
#
# Runs a command up to 5 times, waiting 30, 60, 120, 240 s in between. For the
# composer steps only. COMPOSER_AUTH in the workflows removed most of the
# "curl error 7 ... Connection refused" from api.github.com, but a runner can
# still be refused for several minutes (run 40687710051: three attempts over
# 6 min); the doubling wait covers about 7.5 min. Composer keeps what it
# already fetched in its cache, so a retry only downloads the rest. Each retry
# is reported as a workflow warning, so a flake stays visible.
#
# Usage: retry.sh <command> [args...]

set -u

attempts=5
delay=30
for attempt in $(seq 1 "$attempts"); do
    # api.github.com refuses the dist zips from some runners for the whole job (curl error 7 on every
    # package, every attempt: run 42595062135) while github.com keeps serving git. From the 4th attempt
    # on, a composer install/update/require is run with --prefer-source: slower, but a clone needs only
    # github.com. The composer call may be one argument (bash -c "... && composer install ..."), so the
    # flag is appended to the argument that carries it.
    args=()
    for arg in "$@"; do
        if [ "$attempt" -ge 4 ] && printf '%s' "$arg" | grep -qE 'composer (install|update|require)'; then
            arg="$arg --prefer-source"
            echo "::warning::attempt $attempt: --prefer-source (api.github.com refused)"
        fi
        args+=("$arg")
    done
    "${args[@]}" && exit 0
    status=$?
    if [ "$attempt" -lt "$attempts" ]; then
        echo "::warning::attempt $attempt of $attempts failed (exit $status), retrying in $delay s: $*"
        sleep "$delay"
        delay=$((delay * 2))
    fi
done

exit "$status"

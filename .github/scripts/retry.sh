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
    "$@" && exit 0
    status=$?
    if [ "$attempt" -lt "$attempts" ]; then
        echo "::warning::attempt $attempt of $attempts failed (exit $status), retrying in $delay s: $*"
        sleep "$delay"
        delay=$((delay * 2))
    fi
done

exit "$status"

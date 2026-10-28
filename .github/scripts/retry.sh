#!/usr/bin/env bash
#
# Runs a command up to 3 times, 30 s apart. For the composer steps only: GitHub's
# runners now and then get "curl error 7 ... Connection refused" from
# api.github.com for every dist download at once, which fails the job before a
# single test runs. Composer keeps what it already fetched in its cache, so a
# retry only downloads the rest. Each retry is reported as a workflow warning,
# so the flake stays visible instead of being hidden.
#
# Usage: retry.sh <command> [args...]

set -u

attempts=3
for attempt in $(seq 1 "$attempts"); do
    "$@" && exit 0
    status=$?
    if [ "$attempt" -lt "$attempts" ]; then
        echo "::warning::attempt $attempt of $attempts failed (exit $status), retrying in 30 s: $*"
        sleep 30
    fi
done

exit "$status"

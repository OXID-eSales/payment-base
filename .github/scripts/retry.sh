#!/usr/bin/env bash
#
# Runs a command up to 3 times, 30 s apart. For the composer steps only, as a
# backstop: the cause of the "curl error 7 ... Connection refused" from
# api.github.com was unauthenticated downloads (fixed by COMPOSER_AUTH in the
# workflows); a network blip can still hit a download. Composer keeps what it
# already fetched in its cache, so a retry only downloads the rest. Each retry
# is reported as a workflow warning, so a flake stays visible.
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

#!/usr/bin/env bash
#
# Reads a PHPUnit log written with --display-skipped and fails on a test that skipped itself for a
# missing GraphQL package ("graphql-base is not installed", "oxid-admin-graphql is not installed", ...).
#
#  without  phase 1 (--exclude-group graphql): such a skip is a GraphQL test without the group
#           attribute. It must carry #[Group('graphql')] so that phase 2 runs it.
#  with     phase 2 (--group graphql, GraphQL installed): such a skip is a GraphQL test that did not
#           run although GraphQL is installed. The phase also fails when it ran no test at all.
#
# Usage: graphql-skip-check.sh <without|with> <phpunit log>

set -u

phase="${1:?usage: graphql-skip-check.sh <without|with> <log>}"
log="${2:?usage: graphql-skip-check.sh <without|with> <log>}"
pattern='graphql.* is not installed'

skipped=$(grep -c -i -E "$pattern" "$log" || true)
echo "Tests skipped for a missing GraphQL package: $skipped"
if [ "$skipped" -gt 0 ]; then
    grep -B 2 -i -E "$pattern" "$log"
    if [ "$phase" = 'without' ]; then
        echo "::error title=GraphQL test without the group::$skipped tests skipped for a missing GraphQL package in the run that excludes the group 'graphql'. Give them #[Group('graphql')] so that the GraphQL phase runs them."
    else
        echo "::error title=GraphQL tests skipped::$skipped GraphQL tests were skipped although GraphQL is installed."
    fi
    exit 1
fi

if [ "$phase" = 'with' ] && ! grep -q -E '(OK \([1-9][0-9]* tests?|Tests: [1-9])' "$log"; then
    echo "::error title=No GraphQL test ran::the GraphQL phase ran no test. Check the group 'graphql'."
    exit 1
fi

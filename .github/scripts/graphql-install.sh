#!/usr/bin/env bash
#
# Phase 2 of the integration job: installs GraphQL into the shop that phase 1 tested without it,
# the same way a merchant adds GraphQL to a shop that already runs payment-base. graphql-storefront
# brings graphql-base. Both modules are installed and activated; the activation recompiles the
# container, so payment-base's GraphQL services become live without a re-activation. GraphQLite
# caches its schema on disk, so the cache is cleared at the end.
#
# Run from the docker-eshop-sdk root (the directory with docker-compose.yml), with the component
# checked out at source/test-module.
# Usage: graphql-install.sh <oe-console path in the container, e.g. bin/oe-console>

set -eu

console="${1:?usage: graphql-install.sh <oe-console path>}"
scripts="$(cd "$(dirname "$0")" && pwd)"

docker compose exec -T -e COMPOSER_AUTH php composer require oxid-esales/graphql-storefront \
    --no-interaction --no-update
RETRY_BETWEEN='docker compose exec -T php php /var/www/test-module/.github/scripts/composer-dist-cache-from-codeload.php /var/www/composer.lock' \
    "$scripts/retry.sh" docker compose exec -T -e COMPOSER_AUTH php composer update --no-interaction

for package in graphql-base graphql-storefront; do
    docker compose exec -T php "$console" oe:module:install "/var/www/vendor/oxid-esales/$package"
done
docker compose exec -T php "$console" oe:module:activate oe_graphql_base
docker compose exec -T php "$console" oe:module:activate oe_graphql_storefront

# graphql-base registers its console commands only while it is active: without the command GraphQL
# is not live, and the GraphQL tests would not run against it.
if ! docker compose exec -T php "$console" list --raw | grep -q '^oe:graphql:cache-clear'; then
    echo "::error title=GraphQL not active::graphql-base is installed but not active."
    exit 1
fi
docker compose exec -T php "$console" oe:graphql:cache-clear

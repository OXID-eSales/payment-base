#!/usr/bin/env bash
#
# Fails when any GraphQL package is in the shop. Phase 1 of the integration job runs on a shop
# without GraphQL; a dependency that pulls GraphQL in again would make that phase prove nothing.
#
# Run from the docker-eshop-sdk root (the directory with docker-compose.yml).
# Usage: graphql-absent.sh

set -u

present=''
for package in oxid-esales/graphql-base oxid-esales/graphql-storefront oxid-esales/graphql-admin \
    thecodingmachine/graphqlite webonyx/graphql-php; do
    if docker compose exec -T -e COMPOSER_AUTH php composer show "$package" > /dev/null 2>&1; then
        present="$present $package"
    fi
done
if [ -n "$present" ]; then
    echo "::error title=GraphQL in the shop without GraphQL::installed:$present. Something requires GraphQL again; phase 1 would not prove the module works without it."
    exit 1
fi
echo "No GraphQL package in the shop (graphql-base, graphql-storefront, graphql-admin, GraphQLite, graphql-php)"

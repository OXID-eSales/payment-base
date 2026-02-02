#!/bin/bash

# Composer post-update script for payment-component
# Runs database migrations after composer update/install
#
# Prerequisites:
#   - PHP 8.3 or higher
#   - oxideshop-doctrine-migration-wrapper installed
#
# Usage: ./bin/composer-post-update.sh

set -e

# Get PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION;")
PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")

# Check PHP version >= 8.3
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 3 ]; }; then
    echo "Skipping migrations: PHP 8.3+ required (current: $PHP_VERSION)"
    exit 0
fi

# Check if doctrine-migration-wrapper is installed
if [ ! -d "vendor/oxid-esales/oxideshop-doctrine-migration-wrapper" ]; then
    echo "Skipping migrations: oxideshop-doctrine-migration-wrapper not installed"
    exit 0
fi

echo "Running payment-component database migrations..."

php vendor/bin/doctrine-migrations migrate \
    --configuration=vendor/oxid-esales/payment-component/migration/migrations.yml \
    --db-configuration=vendor/oxid-esales/oxideshop-doctrine-migration-wrapper/src/migrations-db.php \
    --no-interaction \
    --allow-no-migration

echo "Migrations completed."

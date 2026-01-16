<?php

/**
 * Bootstrap file for Integration Tests
 *
 * This bootstrap is used when running integration tests from the OXID shop context.
 * It loads the shop's bootstrap and registers the test classes autoloader.
 *
 * Usage from shop root:
 *   vendor/bin/phpunit -c vendor/oxid-esales/payment-component/tests/phpunit-integration.xml
 */

declare(strict_types=1);

// Find the shop bootstrap - try multiple possible locations
$possibleBootstraps = [
    // When running from shop root (vendor/oxid-esales/payment-component/tests/)
    dirname(__DIR__, 4) . '/source/bootstrap.php',
    // Docker SDK environment (shop source at /var/www/source/)
    '/var/www/source/bootstrap.php',
    // When running from GitHub Actions workspace (standalone setup)
    getenv('GITHUB_WORKSPACE') ? getenv('GITHUB_WORKSPACE') . '/shop/source/bootstrap.php' : null,
];

$shopBootstrap = null;
foreach ($possibleBootstraps as $path) {
    if ($path !== null && file_exists($path)) {
        $shopBootstrap = $path;
        break;
    }
}

if ($shopBootstrap === null) {
    throw new RuntimeException(
        'OXID shop bootstrap not found. Integration tests must be run from shop context. ' .
        'Searched paths: ' . implode(', ', array_filter($possibleBootstraps))
    );
}

// Load shop bootstrap (includes shop's autoloader)
require_once $shopBootstrap;

// Register autoloader for migration classes (not auto-discovered by composer in path repos)
$possibleMigrationDirs = [
    '/var/www/extensions/payment-component/migration/data',
    dirname(__DIR__) . '/migration/data',
    dirname(__DIR__, 4) . '/vendor/oxid-esales/payment-component/migration/data',
];

foreach ($possibleMigrationDirs as $migrationDir) {
    if (is_dir($migrationDir)) {
        spl_autoload_register(static function (string $class) use ($migrationDir): void {
            $prefix = 'OxidEsales\\PaymentComponent\\Migrations\\';
            $prefixLength = strlen($prefix);

            if (strncmp($class, $prefix, $prefixLength) !== 0) {
                return;
            }

            $relativeClass = substr($class, $prefixLength);
            $file = $migrationDir . '/' . $relativeClass . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
        break;
    }
}

// Register autoloader for test classes (autoload-dev is not loaded in production installs)
// Try multiple possible test directories
$possibleTestDirs = [
    __DIR__,
    '/var/www/test-module/tests',
    '/var/www/extensions/payment-component/tests',
    dirname(__DIR__, 4) . '/vendor/oxid-esales/payment-component/tests',
];

foreach ($possibleTestDirs as $testDir) {
    if (is_dir($testDir)) {
        spl_autoload_register(static function (string $class) use ($testDir): void {
            $prefix = 'OxidEsales\\PaymentComponent\\Tests\\';
            $prefixLength = strlen($prefix);

            if (strncmp($class, $prefix, $prefixLength) !== 0) {
                return;
            }

            $relativeClass = substr($class, $prefixLength);
            $file = $testDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
        break;
    }
}

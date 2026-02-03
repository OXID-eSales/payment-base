<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class MigrationPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'oxid-esales/payment-component';
    private const MIGRATION_WRAPPER = 'oxid-esales/oxideshop-doctrine-migration-wrapper';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstallOrUpdate',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageInstallOrUpdate',
        ];
    }

    public function onPackageInstallOrUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        $package = method_exists($operation, 'getPackage')
            ? $operation->getPackage()
            : $operation->getTargetPackage();

        if ($package->getName() !== self::PACKAGE_NAME) {
            return;
        }

        $this->runMigrations();
    }

    private function runMigrations(): void
    {
        if (!$this->checkPhpVersion()) {
            $this->io->write('<comment>Skipping payment-component migrations: PHP 8.3+ required</comment>');
            return;
        }

        if (!$this->isMigrationWrapperInstalled()) {
            $this->io->write('<comment>Skipping payment-component migrations: migration wrapper not installed</comment>');
            return;
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $migrationConfig = $vendorDir . '/oxid-esales/payment-component/migration/migrations.yml';
        $dbConfig = $vendorDir . '/oxid-esales/oxideshop-doctrine-migration-wrapper/src/migrations-db.php';

        if (!file_exists($migrationConfig)) {
            $this->io->write('<comment>Skipping payment-component migrations: migration config not found</comment>');
            return;
        }

        if (!file_exists($dbConfig)) {
            $this->io->write('<comment>Skipping payment-component migrations: DB config not found</comment>');
            return;
        }

        if (!$this->isDatabaseAccessible($vendorDir)) {
            $this->io->write('<comment>Skipping payment-component migrations: database not accessible (shop not set up yet?)</comment>');
            return;
        }

        $this->io->write('<info>Running payment-component database migrations...</info>');

        $command = sprintf(
            'php %s/bin/doctrine-migrations migrate --configuration=%s --db-configuration=%s --no-interaction --allow-no-migration 2>&1',
            $vendorDir,
            escapeshellarg($migrationConfig),
            escapeshellarg($dbConfig)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        foreach ($output as $line) {
            $this->io->write('  ' . $line);
        }

        if ($returnCode === 0) {
            $this->io->write('<info>Payment-component migrations completed.</info>');
        } else {
            $this->io->write('<error>Payment-component migrations failed with code: ' . $returnCode . '</error>');
        }
    }

    private function checkPhpVersion(): bool
    {
        return PHP_MAJOR_VERSION > 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 3);
    }

    private function isMigrationWrapperInstalled(): bool
    {
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === self::MIGRATION_WRAPPER) {
                return true;
            }
        }

        return false;
    }

    private function isDatabaseAccessible(string $vendorDir): bool
    {
        $configFile = dirname($vendorDir) . '/source/config.inc.php';

        if (!file_exists($configFile)) {
            return false;
        }

        // Try to check if DB credentials are configured (not placeholders)
        $configContent = file_get_contents($configFile);
        if ($configContent === false) {
            return false;
        }

        // Check for placeholder values that indicate shop isn't set up
        if (strpos($configContent, '<dbHost>') !== false || strpos($configContent, '<dbName>') !== false) {
            return false;
        }

        // Try a simple database connection test
        $testScript = <<<'PHP'
<?php
error_reporting(0);
$configFile = $argv[1];
if (!file_exists($configFile)) {
    exit(1);
}

// Extract DB config without loading full OXID
$content = file_get_contents($configFile);
preg_match("/\\\$this->dbHost\s*=\s*['\"]([^'\"]+)['\"]/", $content, $hostMatch);
preg_match("/\\\$this->dbName\s*=\s*['\"]([^'\"]+)['\"]/", $content, $nameMatch);
preg_match("/\\\$this->dbUser\s*=\s*['\"]([^'\"]+)['\"]/", $content, $userMatch);
preg_match("/\\\$this->dbPwd\s*=\s*['\"]([^'\"]*?)['\"]/", $content, $pwdMatch);
preg_match("/\\\$this->dbPort\s*=\s*['\"]?([^'\";\s]+)/", $content, $portMatch);

$host = $hostMatch[1] ?? '';
$name = $nameMatch[1] ?? '';
$user = $userMatch[1] ?? '';
$pwd = $pwdMatch[1] ?? '';
$port = $portMatch[1] ?? '3306';

if (empty($host) || empty($name) || empty($user)) {
    exit(1);
}

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$name";
    $pdo = new PDO($dsn, $user, $pwd, [PDO::ATTR_TIMEOUT => 2]);
    echo "OK";
    exit(0);
} catch (Exception $e) {
    exit(1);
}
PHP;

        $tempFile = sys_get_temp_dir() . '/payment_component_db_check_' . uniqid() . '.php';
        file_put_contents($tempFile, $testScript);

        $output = [];
        $returnCode = 0;
        exec(sprintf('php %s %s 2>/dev/null', escapeshellarg($tempFile), escapeshellarg($configFile)), $output, $returnCode);

        @unlink($tempFile);

        return $returnCode === 0 && isset($output[0]) && $output[0] === 'OK';
    }
}

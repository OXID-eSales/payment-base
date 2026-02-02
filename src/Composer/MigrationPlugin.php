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

        $this->io->write('<info>Running payment-component database migrations...</info>');

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $migrationConfig = $vendorDir . '/oxid-esales/payment-component/migration/migrations.yml';
        $dbConfig = $vendorDir . '/oxid-esales/oxideshop-doctrine-migration-wrapper/src/migrations-db.php';

        if (!file_exists($migrationConfig)) {
            $this->io->write('<warning>Migration config not found: ' . $migrationConfig . '</warning>');
            return;
        }

        if (!file_exists($dbConfig)) {
            $this->io->write('<warning>DB config not found: ' . $dbConfig . '</warning>');
            return;
        }

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
}

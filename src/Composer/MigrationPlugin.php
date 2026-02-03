<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

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
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        $this->composer = $event->getComposer();
        $this->io = $event->getIO();

        $this->runMigrations();
    }

    private function runMigrations(): void
    {
        if (!$this->isPaymentComponentInstalled()) {
            return;
        }

        if (!$this->checkPhpVersion()) {
            $this->io->write('<comment>Skipping payment-component migrations: PHP 8.2+ required</comment>');
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

        // Check if shop config exists and has real DB credentials (not placeholders)
        $shopConfigFile = dirname($vendorDir) . '/source/config.inc.php';
        if (!file_exists($shopConfigFile)) {
            $this->io->write('<comment>Skipping payment-component migrations: shop not configured yet</comment>');
            return;
        }

        $configContent = file_get_contents($shopConfigFile);
        if ($configContent && strpos($configContent, '<dbHost>') !== false) {
            $this->io->write('<comment>Skipping payment-component migrations: shop not configured yet</comment>');
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
            $this->io->write('<comment>Payment-component migrations skipped (database not ready)</comment>');
        }
    }

    private function checkPhpVersion(): bool
    {
        return PHP_MAJOR_VERSION > 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 2);
    }

    private function isPaymentComponentInstalled(): bool
    {
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === self::PACKAGE_NAME) {
                return true;
            }
        }

        return false;
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

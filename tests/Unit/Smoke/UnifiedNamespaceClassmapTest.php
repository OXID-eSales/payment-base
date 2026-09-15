<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 93 / Probe 1 — package-shape integrity guard.
 *
 * Sprint I (2026-04-23) flipped this package from
 * `type: composer-plugin` to `type: oxideshop-module` so it could ship
 * the unified "Payment" admin tab. That decision is load-bearing —
 * an accidental revert breaks the shared admin tab and re-greens
 * Sprint J's CI break (the unified-namespace classmap desync at
 * `source/bootstrap.php:184`).
 *
 * This test runs in PC's standalone `styles` CI job (cheap — no shop
 * boot, no MySQL) and refuses any future PR that walks the package
 * type backwards. The autoload-classmap regression itself is caught
 * by Probe 2 (the `bin/oe-console list` workflow step) which runs in
 * the `install_shop_with_module` job where the shop vendor is
 * actually present.
 *
 * @see ../../../docs/oe_payments_docs/daniil_dev_log/20260505/sprints/sprint-93-ci-bootstrap-fix.md
 */
final class UnifiedNamespaceClassmapTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../../..';
    private const MODULE_ID = 'oe_payment_base';

    public function testComposerJsonDeclaresPackageAsOxideshopModule(): void
    {
        $composer = $this->readJson(self::PACKAGE_ROOT . '/composer.json');

        self::assertSame(
            'oxideshop-module',
            $composer['type'] ?? null,
            'payment-base composer.json `type` must stay '
            . '`oxideshop-module` so the shop installs it as a module. '
            . 'See Sprint 93 / Sprint I §47.'
        );

        // `extra.oxideshop.target-directory` used to be asserted here as well.
        // It no longer says anything: since OXID 7 a module is not copied to
        // source/modules/ at all — it stays in vendor/ and only assets/ is
        // symlinked to source/out/modules/<moduleId> by ModuleFilesInstaller.
        // The key is read by ThemePackageInstaller only;
        // ModulePackageInstaller::getModuleTargetDir() has no caller.
        // The module id itself is guarded below, against metadata.php.
    }

    public function testMetadataPhpDeclaresPaymentBaseModuleId(): void
    {
        $metadataPath = self::PACKAGE_ROOT . '/metadata.php';
        self::assertFileExists(
            $metadataPath,
            'payment-base must ship metadata.php — it is now an '
            . 'oxideshop-module, not a composer-plugin. See Sprint 93.'
        );

        $sMetadataVersion = null;
        $aModule = [];
        require $metadataPath;

        self::assertSame(
            self::MODULE_ID,
            $aModule['id'] ?? null,
            'metadata.php must declare id `' . self::MODULE_ID . '` — '
            . 'the OXID admin and CI workflow both reference it by '
            . 'this exact key.'
        );
        self::assertArrayHasKey(
            'PaymentAdmin',
            $aModule['controllers'] ?? [],
            'metadata.php must register the `PaymentAdmin` controller '
            . 'so the menu.xml `<TAB cl="PaymentAdmin">` resolves at '
            . 'runtime.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "Failed to decode JSON at {$path}");
        return $decoded;
    }
}

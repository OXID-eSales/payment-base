<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * The archive composer installs (`--prefer-dist`, GitHub's zip of a tag) leaves out every path `.gitattributes` marks
 * `export-ignore`. A command declared under composer.json "bin" that is left out never reaches the shop's vendor/bin:
 * composer skips the missing file without an error. v1.6.1 to v1.7.4 excluded the whole `bin/` folder and shipped
 * neither `oe-payment-base-migrate` nor `oe-payment-base-schema-check` (found on a production shop, 2026-11-12).
 */
final class DistArchiveTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testEveryDeclaredCommand_IsShippedInTheArchive(): void
    {
        $bins = $this->declaredBins();
        self::assertNotSame([], $bins, 'composer.json declares the migration commands under "bin"');

        foreach ($bins as $bin) {
            self::assertFileExists(self::ROOT . '/' . $bin);
            self::assertTrue(is_executable(self::ROOT . '/' . $bin), $bin . ' must be executable');
            foreach ($this->exportIgnoredPaths() as $ignored) {
                self::assertFalse(
                    $bin === $ignored || str_starts_with($bin, rtrim($ignored, '/') . '/'),
                    sprintf('%s is declared under composer.json "bin" but .gitattributes export-ignores /%s', $bin, $ignored),
                );
            }
        }
    }

    public function testTheTwoMigrationCommands_AreDeclared(): void
    {
        self::assertSame(['bin/oe-payment-base-migrate', 'bin/oe-payment-base-schema-check'], $this->declaredBins());
    }

    /**
     * @return list<string>
     */
    private function declaredBins(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);

        return array_values(array_map('strval', $composer['bin'] ?? []));
    }

    /**
     * Root-anchored paths `.gitattributes` export-ignores, without the leading slash.
     *
     * @return list<string>
     */
    private function exportIgnoredPaths(): array
    {
        $paths = [];
        foreach (file(self::ROOT . '/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) >= 2 && in_array('export-ignore', $fields, true) && str_starts_with($fields[0], '/')) {
                $paths[] = ltrim($fields[0], '/');
            }
        }

        return $paths;
    }
}

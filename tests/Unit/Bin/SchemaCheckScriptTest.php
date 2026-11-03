<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/**
 * VR-16 O3 — `oe-payment-base-schema-check` is the deploy gate: pay1 ran two
 * days on a schema three migrations behind, and the first sign was a customer
 * mail sent before the payment. The script asks the shop's migration wrapper
 * (`migrations:up-to-date`, suite oe_payment_base) and passes its answer on.
 *
 * Run against a throw-away project root with a stand-in wrapper that records
 * its arguments and exits with a scripted code.
 */
final class SchemaCheckScriptTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/pb-schema-check-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        mkdir($this->projectRoot . '/source', 0777, true);
        file_put_contents(
            $this->projectRoot . '/source/config.inc.php',
            "<?php\n\$this->dbHost = 'mysql';\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (['vendor/bin/oe-eshop-db_migrate', 'wrapper-args', 'source/config.inc.php'] as $file) {
            @unlink($this->projectRoot . '/' . $file);
        }
        foreach (['vendor/bin', 'vendor', 'source', ''] as $dir) {
            @rmdir($this->projectRoot . '/' . $dir);
        }
    }

    public function testAnUpToDateSchemaPasses(): void
    {
        $this->wrapperExitingWith(0);

        [$exitCode, $output] = $this->runScript();

        self::assertSame(0, $exitCode, $output);
        self::assertSame('migrations:up-to-date oe_payment_base', $this->wrapperArguments());
        self::assertStringContainsString('schema is up to date', $output);
    }

    public function testPendingMigrationsFailTheCheck(): void
    {
        $this->wrapperExitingWith(1);

        [$exitCode, $output] = $this->runScript();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('oe-payment-base-migrate', $output, 'tells how to fix it');
    }

    public function testWithoutTheMigrationWrapperTheCheckFails(): void
    {
        // Unlike the migrate script, a check that cannot look must not pass.
        [$exitCode, $output] = $this->runScript();

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('cannot check', $output);
    }

    public function testAShopThatIsNotSetUpFailsTheCheck(): void
    {
        $this->wrapperExitingWith(0);
        file_put_contents($this->projectRoot . '/source/config.inc.php', "<?php\n\$this->dbHost = '<dbHost>';\n");

        [$exitCode, $output] = $this->runScript();

        self::assertSame(2, $exitCode, $output);
        self::assertSame('', $this->wrapperArguments(), 'the wrapper is not asked');
    }

    private function wrapperExitingWith(int $exitCode): void
    {
        $wrapper = $this->projectRoot . '/vendor/bin/oe-eshop-db_migrate';
        file_put_contents(
            $wrapper,
            "#!/usr/bin/env bash\necho -n \"\$*\" > \"" . $this->projectRoot . "/wrapper-args\"\nexit " . $exitCode . "\n"
        );
        chmod($wrapper, 0755);
    }

    private function wrapperArguments(): string
    {
        $file = $this->projectRoot . '/wrapper-args';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(): array
    {
        $script = dirname(__DIR__, 3) . '/bin/oe-payment-base-schema-check';
        $process = proc_open(
            ['bash', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
            ['OE_PROJECT_ROOT' => $this->projectRoot, 'PATH' => (string) getenv('PATH')]
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}

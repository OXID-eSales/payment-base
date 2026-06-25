<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service\Factory;

use Closure;
use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\NullFileLogger;
use OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Phase 1 TDD tests: closure gating seam on AbstractFileLoggerFactory.
 *
 * Red → Green → Refactor for the ?\Closure $isEnabled = null addition:
 *   - closure returning false  ⇒ create() returns NullFileLogger
 *   - closure returning true   ⇒ create() returns FileLogger
 *   - null closure (default)   ⇒ create() returns FileLogger (back-compat / LSP)
 *   - closure invoked lazily   ⇒ not called in ctor, only inside create()
 */
#[CoversClass(\OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory::class)]
#[Group('logging')]
#[Group('phase-1-closure-gating')]
final class AbstractFileLoggerFactoryClosureGatingTest extends TestCase
{
    private string $testShopDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testShopDir = sys_get_temp_dir() . '/stripe_factory_gating_test_' . uniqid();
    }

    /**
     * Disabled closure ⇒ create() returns NullFileLogger (no file written).
     */
    public function testCreateReturnsNullFileLoggerWhenClosureReturnsFalse(): void
    {
        $isEnabled = static fn (): bool => false;
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST', $isEnabled);

        $logger = $factory->create();

        $this->assertInstanceOf(NullFileLogger::class, $logger);
    }

    /**
     * Enabled closure ⇒ create() returns the real FileLogger.
     */
    public function testCreateReturnsFileLoggerWhenClosureReturnsTrue(): void
    {
        $isEnabled = static fn (): bool => true;
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST', $isEnabled);

        $logger = $factory->create();

        $this->assertInstanceOf(FileLogger::class, $logger);
    }

    /**
     * Null closure (default) ⇒ create() returns FileLogger (back-compat / LSP anchor).
     * This MUST remain true even after Phase 1; overlaps Phase 0 intentionally.
     */
    public function testCreateReturnsFileLoggerWhenClosureIsNull(): void
    {
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST', null);

        $logger = $factory->create();

        $this->assertInstanceOf(FileLogger::class, $logger);
    }

    /**
     * Lazy evaluation: the closure must NOT be called in the constructor —
     * only inside create(). A closure that throws proves this: constructing
     * the factory succeeds; create() would trigger it. We verify that calling
     * create() is what invokes the closure, not construction.
     */
    public function testClosureIsInvokedLazilyInsideCreate(): void
    {
        $callCount = 0;
        $isEnabled = static function () use (&$callCount): bool {
            $callCount++;
            return true;
        };

        // Construction alone must NOT invoke the closure
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST', $isEnabled);
        $this->assertSame(0, $callCount, 'Closure must not be called during construction');

        // Each create() call invokes it exactly once
        $factory->create();
        $this->assertSame(1, $callCount, 'Closure must be called exactly once per create()');

        $factory->create();
        $this->assertSame(2, $callCount, 'Closure must be called on each create() invocation');
    }

    /**
     * Build a minimal concrete subclass of AbstractFileLoggerFactory with no
     * Registry dependency, forwarding the optional closure to the parent ctor.
     *
     * In Phase 1 the subclass uses the NEW optional ctor arg. This subclass
     * intentionally calls parent::__construct($isEnabled) so the test can
     * exercise the seam directly — the four real Stripe factories require
     * ZERO changes because they have no explicit ctor of their own.
     */
    private function makeFactory(
        string $shopDir,
        string $logFile,
        string $prefix,
        ?Closure $isEnabled = null
    ): AbstractFileLoggerFactory {
        return new class ($shopDir, $logFile, $prefix, $isEnabled) extends AbstractFileLoggerFactory {
            public function __construct(
                private readonly string $testShopDir,
                private readonly string $testLogFile,
                private readonly string $testPrefix,
                ?Closure $isEnabled = null,
            ) {
                parent::__construct($isEnabled);
            }

            protected function getLogFile(): string
            {
                return $this->testLogFile;
            }

            protected function getPrefix(): string
            {
                return $this->testPrefix;
            }

            protected function getShopDirectory(): string
            {
                return $this->testShopDir;
            }
        };
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service\Factory;

use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Phase 0 characterization: AbstractFileLoggerFactory::create() always returns
 * a real FileLogger (never NullFileLogger) when no closure gating exists.
 *
 * This is the LSP/back-compat anchor for Phase 1. After Phase 1 adds the
 * optional ?\Closure $isEnabled = null parameter, the null-default path MUST
 * still return a FileLogger — these tests prove that contract.
 */
#[CoversClass(\OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory::class)]
#[Group('logging')]
#[Group('phase-0-characterization')]
final class AbstractFileLoggerFactoryTest extends TestCase
{
    private string $testShopDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testShopDir = sys_get_temp_dir() . '/stripe_factory_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Characterization: create() returns an instance of FileLoggerInterface.
     */
    public function testCreateReturnsFileLoggerInterface(): void
    {
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST');

        $logger = $factory->create();

        $this->assertInstanceOf(FileLoggerInterface::class, $logger);
    }

    /**
     * Characterization: create() returns a concrete FileLogger (the always-on impl),
     * NOT NullFileLogger. This is the parity anchor: Phase 1 must not break this
     * for the null-closure path.
     */
    public function testCreateReturnsConcreteFileLogger(): void
    {
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST');

        $logger = $factory->create();

        $this->assertInstanceOf(FileLogger::class, $logger);
    }

    /**
     * Characterization: create() throws RuntimeException when shop directory is empty.
     * Guards against misconfigured environment (empty sShopDir).
     */
    public function testCreateThrowsWhenShopDirectoryIsEmpty(): void
    {
        $factory = $this->makeFactory('', 'log/test.log', 'TEST');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shop directory not configured');

        $factory->create();
    }

    /**
     * Characterization: create() is callable multiple times — each call returns
     * a fresh FileLogger (no singleton / stateful caching in the factory today).
     */
    public function testCreateReturnsFreshInstanceOnEachCall(): void
    {
        $factory = $this->makeFactory($this->testShopDir, 'log/test.log', 'TEST');

        $first = $factory->create();
        $second = $factory->create();

        $this->assertNotSame($first, $second);
    }

    /**
     * Build a concrete minimal subclass of AbstractFileLoggerFactory with
     * configurable shop dir, log file, and prefix — no Registry dependency.
     */
    private function makeFactory(string $shopDir, string $logFile, string $prefix): AbstractFileLoggerFactory
    {
        return new class ($shopDir, $logFile, $prefix) extends AbstractFileLoggerFactory {
            public function __construct(
                private readonly string $testShopDir,
                private readonly string $testLogFile,
                private readonly string $testPrefix,
            ) {
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

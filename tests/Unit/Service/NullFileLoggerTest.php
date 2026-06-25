<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\NullFileLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Service\NullFileLogger::class)]
class NullFileLoggerTest extends TestCase
{
    public function testImplementsFileLoggerInterface(): void
    {
        $logger = new NullFileLogger();

        $this->assertInstanceOf(FileLoggerInterface::class, $logger);
    }

    public function testLogDoesNothing(): void
    {
        $logger = new NullFileLogger();

        // Should not throw or produce side effects
        $logger->log('test message');
        $logger->log('test message', ['key' => 'value']);
        $logger->log('', []);

        $this->assertTrue(true); // Reached without error
    }

    public function testLogWithComplexContext(): void
    {
        $logger = new NullFileLogger();

        $logger->log('complex', [
            'nested' => ['data' => true],
            'count' => 42,
            'items' => ['a', 'b', 'c'],
        ]);

        $this->assertTrue(true); // Reached without error
    }
}

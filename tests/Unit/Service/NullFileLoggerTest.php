<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Service\NullFileLogger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\NullFileLogger
 */
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

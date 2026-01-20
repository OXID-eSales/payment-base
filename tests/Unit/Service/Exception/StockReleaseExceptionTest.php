<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Exception;

use OxidEsales\PaymentComponent\Service\Exception\StockReleaseException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\Exception\StockReleaseException
 */
class StockReleaseExceptionTest extends TestCase
{
    public function testExceptionContainsContractId(): void
    {
        $exception = new StockReleaseException('contract123', 'Database error');

        $this->assertEquals('contract123', $exception->getContractId());
    }

    public function testExceptionMessageIsFormatted(): void
    {
        $exception = new StockReleaseException('abc', 'Connection failed');

        $this->assertStringContainsString('abc', $exception->getMessage());
        $this->assertStringContainsString('Connection failed', $exception->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $exception = new StockReleaseException('c1', 'Error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \Exception('Original error');
        $exception = new StockReleaseException('c1', 'Wrapped', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}

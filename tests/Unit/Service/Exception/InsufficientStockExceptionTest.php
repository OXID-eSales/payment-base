<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Exception;

use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException
 */
class InsufficientStockExceptionTest extends TestCase
{
    public function testExceptionContainsProductInfo(): void
    {
        $exception = new InsufficientStockException('product123', 5, 2);

        $this->assertEquals('product123', $exception->getProductId());
        $this->assertEquals(5, $exception->getRequested());
        $this->assertEquals(2, $exception->getAvailable());
    }

    public function testExceptionMessageIsFormatted(): void
    {
        $exception = new InsufficientStockException('abc', 10, 3);

        $this->assertStringContainsString('abc', $exception->getMessage());
        $this->assertStringContainsString('10', $exception->getMessage());
        $this->assertStringContainsString('3', $exception->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $exception = new InsufficientStockException('p1', 1, 0);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}

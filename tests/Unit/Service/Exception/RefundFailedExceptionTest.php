<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service\Exception;

use OxidEsales\PaymentBase\Service\Exception\RefundFailedException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Service\Exception\RefundFailedException::class)]
class RefundFailedExceptionTest extends TestCase
{
    public function testExceptionContainsContractId(): void
    {
        $exception = new RefundFailedException('contract123', 'Already refunded');

        $this->assertEquals('contract123', $exception->getContractId());
    }

    public function testExceptionMessageIsFormatted(): void
    {
        $exception = new RefundFailedException('abc', 'Refund not allowed');

        $this->assertStringContainsString('abc', $exception->getMessage());
        $this->assertStringContainsString('Refund not allowed', $exception->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $exception = new RefundFailedException('c1', 'Error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \Exception('Original error');
        $exception = new RefundFailedException('c1', 'Wrapped', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}

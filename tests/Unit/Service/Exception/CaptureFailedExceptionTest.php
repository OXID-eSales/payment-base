<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service\Exception;

use OxidEsales\PaymentBase\Service\Exception\CaptureFailedException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentBase\Service\Exception\CaptureFailedException
 */
class CaptureFailedExceptionTest extends TestCase
{
    public function testExceptionContainsContractId(): void
    {
        $exception = new CaptureFailedException('contract123', 'Insufficient funds');

        $this->assertEquals('contract123', $exception->getContractId());
    }

    public function testExceptionMessageIsFormatted(): void
    {
        $exception = new CaptureFailedException('abc', 'Provider declined');

        $this->assertStringContainsString('abc', $exception->getMessage());
        $this->assertStringContainsString('Provider declined', $exception->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $exception = new CaptureFailedException('c1', 'Error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \Exception('Original error');
        $exception = new CaptureFailedException('c1', 'Wrapped', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}

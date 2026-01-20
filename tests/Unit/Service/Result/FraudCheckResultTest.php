<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Result;

use OxidEsales\PaymentComponent\Service\Result\FraudCheckResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\Result\FraudCheckResult
 */
class FraudCheckResultTest extends TestCase
{
    public function testPassedFactoryCreatesPassed(): void
    {
        $result = FraudCheckResult::passed(0.25);

        $this->assertTrue($result->passed);
        $this->assertTrue($result->isPassed());
        $this->assertFalse($result->isFailed());
        $this->assertEquals(0.25, $result->score);
        $this->assertEquals('', $result->reason);
    }

    public function testFailedFactoryCreatesFailed(): void
    {
        $result = FraudCheckResult::failed(0.85, 'High risk score');

        $this->assertFalse($result->passed);
        $this->assertFalse($result->isPassed());
        $this->assertTrue($result->isFailed());
        $this->assertEquals(0.85, $result->score);
        $this->assertEquals('High risk score', $result->reason);
    }

    public function testConstructorWithAllParameters(): void
    {
        $result = new FraudCheckResult(true, 0.5, 'Custom reason');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.5, $result->score);
        $this->assertEquals('Custom reason', $result->reason);
    }

    public function testConstructorWithDefaultReason(): void
    {
        $result = new FraudCheckResult(false, 0.9);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.9, $result->score);
        $this->assertEquals('', $result->reason);
    }
}

<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Mcp\Http;

use OxidEsales\PaymentBase\Mcp\Http\ApcuRateLimiter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Mcp\Http\ApcuRateLimiter::class)]
class ApcuRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu not available — skipping rate limiter tests');
        }
    }

    public function testFirstRequestIsAllowed(): void
    {
        $limiter = new ApcuRateLimiter(10, 60, 'test_rate_first:');

        $this->assertTrue($limiter->isAllowed('192.168.1.1'));
    }

    public function testRequestsWithinLimitAreAllowed(): void
    {
        $limiter = new ApcuRateLimiter(5, 60, 'test_rate_within:');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->isAllowed('192.168.1.2'), "Request {$i} should be allowed");
        }
    }

    public function testRequestsExceedingLimitAreRejected(): void
    {
        $limiter = new ApcuRateLimiter(3, 60, 'test_rate_exceed:');

        $this->assertTrue($limiter->isAllowed('192.168.1.3'));
        $this->assertTrue($limiter->isAllowed('192.168.1.3'));
        $this->assertTrue($limiter->isAllowed('192.168.1.3'));
        $this->assertFalse($limiter->isAllowed('192.168.1.3'));
        $this->assertFalse($limiter->isAllowed('192.168.1.3'));
    }

    public function testDifferentIpsHaveSeparateLimits(): void
    {
        $limiter = new ApcuRateLimiter(2, 60, 'test_rate_separate:');

        $this->assertTrue($limiter->isAllowed('10.0.0.1'));
        $this->assertTrue($limiter->isAllowed('10.0.0.1'));
        $this->assertFalse($limiter->isAllowed('10.0.0.1'));

        // Different IP still has full quota
        $this->assertTrue($limiter->isAllowed('10.0.0.2'));
        $this->assertTrue($limiter->isAllowed('10.0.0.2'));
        $this->assertFalse($limiter->isAllowed('10.0.0.2'));
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Middleware;

use OxidEsales\PaymentComponent\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Middleware\RateLimitMiddleware
 */
class RateLimitMiddlewareTest extends TestCase
{
    private RateLimitMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new RateLimitMiddleware();
    }

    public function testAllowsRequestWithinRateLimit(): void
    {
        // Arrange
        $ipAddress = '192.168.1.100';

        // Act & Assert: First request should pass
        $this->assertTrue($this->middleware->checkRateLimit($ipAddress));
    }

    public function testAllowsMultipleRequestsWithinLimit(): void
    {
        // Arrange
        $ipAddress = '192.168.1.101';

        // Act: Make 10 requests (the limit)
        for ($i = 0; $i < 10; $i++) {
            $result = $this->middleware->checkRateLimit($ipAddress);
            $this->assertTrue($result, "Request $i should be allowed");
        }
    }

    public function testBlocksRequestsExceedingRateLimit(): void
    {
        // Arrange
        $ipAddress = '192.168.1.102';

        // Act: Make 10 allowed requests
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->checkRateLimit($ipAddress);
        }

        // Assert: 11th request should be blocked
        $this->assertFalse($this->middleware->checkRateLimit($ipAddress));
    }

    public function testTracksRateLimitPerIpIndependently(): void
    {
        // Arrange
        $ip1 = '192.168.1.103';
        $ip2 = '192.168.1.104';

        // Act: Exhaust rate limit for IP1
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->checkRateLimit($ip1);
        }

        // Assert: IP1 blocked, but IP2 still allowed
        $this->assertFalse($this->middleware->checkRateLimit($ip1));
        $this->assertTrue($this->middleware->checkRateLimit($ip2));
    }

    public function testRateLimitResetsAfterTimeWindow(): void
    {
        // Arrange: Middleware with 1-second window for testing
        $middleware = new RateLimitMiddleware(10, 1);
        $ipAddress = '192.168.1.105';

        // Act: Exhaust rate limit
        for ($i = 0; $i < 10; $i++) {
            $middleware->checkRateLimit($ipAddress);
        }

        $this->assertFalse($middleware->checkRateLimit($ipAddress));

        // Wait for window to expire
        sleep(2);

        // Assert: Rate limit reset, request allowed
        $this->assertTrue($middleware->checkRateLimit($ipAddress));
    }

    public function testGetRemainingCallsReturnsCorrectCount(): void
    {
        // Arrange
        $ipAddress = '192.168.1.106';

        // Act & Assert: Initially 10 calls remaining
        $this->assertEquals(10, $this->middleware->getRemainingCalls($ipAddress));

        // Make 3 requests
        for ($i = 0; $i < 3; $i++) {
            $this->middleware->checkRateLimit($ipAddress);
        }

        // Assert: 7 calls remaining
        $this->assertEquals(7, $this->middleware->getRemainingCalls($ipAddress));
    }

    public function testGetRemainingCallsReturnsZeroWhenExceeded(): void
    {
        // Arrange
        $ipAddress = '192.168.1.107';

        // Act: Exhaust rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->checkRateLimit($ipAddress);
        }

        // Assert: 0 calls remaining
        $this->assertEquals(0, $this->middleware->getRemainingCalls($ipAddress));

        // Try more requests
        $this->middleware->checkRateLimit($ipAddress);
        $this->middleware->checkRateLimit($ipAddress);

        // Still 0 (doesn't go negative)
        $this->assertEquals(0, $this->middleware->getRemainingCalls($ipAddress));
    }

    public function testResetRateLimitClearsTracking(): void
    {
        // Arrange
        $ipAddress = '192.168.1.108';

        // Act: Exhaust rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->checkRateLimit($ipAddress);
        }

        $this->assertFalse($this->middleware->checkRateLimit($ipAddress));

        // Reset
        $this->middleware->resetRateLimit($ipAddress);

        // Assert: Can make requests again
        $this->assertTrue($this->middleware->checkRateLimit($ipAddress));
        $this->assertEquals(9, $this->middleware->getRemainingCalls($ipAddress));
    }

    public function testCustomRateLimitConfiguration(): void
    {
        // Arrange: 5 calls per minute
        $middleware = new RateLimitMiddleware(5, 60);
        $ipAddress = '192.168.1.109';

        // Act: Make 5 requests
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($middleware->checkRateLimit($ipAddress));
        }

        // Assert: 6th request blocked
        $this->assertFalse($middleware->checkRateLimit($ipAddress));
    }

    public function testHandlesEmptyIpAddress(): void
    {
        // Arrange
        $emptyIp = '';

        // Act & Assert: Should handle gracefully
        $this->assertTrue($this->middleware->checkRateLimit($emptyIp));
    }

    public function testHandlesIpv6Addresses(): void
    {
        // Arrange
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        // Act
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->middleware->checkRateLimit($ipv6));
        }

        // Assert: 11th request blocked
        $this->assertFalse($this->middleware->checkRateLimit($ipv6));
    }

    public function testCleansUpExpiredEntries(): void
    {
        // Arrange: 1-second window
        $middleware = new RateLimitMiddleware(10, 1);
        $ip1 = '192.168.1.110';
        $ip2 = '192.168.1.111';

        // Act: Make requests from two IPs
        $middleware->checkRateLimit($ip1);
        $middleware->checkRateLimit($ip2);

        // Wait for expiration
        sleep(2);

        // New request should trigger cleanup
        $middleware->checkRateLimit($ip1);

        // Assert: Both IPs have fresh windows
        $this->assertEquals(9, $middleware->getRemainingCalls($ip1));
        $this->assertEquals(10, $middleware->getRemainingCalls($ip2)); // Expired, reset
    }

    public function testGetRetryAfterReturnsCorrectSeconds(): void
    {
        // Arrange
        $ipAddress = '192.168.1.112';

        // Act: Exhaust rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->checkRateLimit($ipAddress);
        }

        // Assert: Retry-After should be ~60 seconds (within margin)
        $retryAfter = $this->middleware->getRetryAfter($ipAddress);
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function testGetRetryAfterReturnsZeroWhenNotLimited(): void
    {
        // Arrange
        $ipAddress = '192.168.1.113';

        // Act & Assert: No rate limit hit yet
        $this->assertEquals(0, $this->middleware->getRetryAfter($ipAddress));
    }
}

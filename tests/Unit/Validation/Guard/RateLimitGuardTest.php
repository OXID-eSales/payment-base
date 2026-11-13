<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Tests\Support\Validation\InMemoryRateLimitStore;
use OxidEsales\PaymentBase\Validation\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitConfigInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL, Mythos O-08): the limit counts per visitor (client IP), not per plugin id or session,
 * which the caller chooses freely.
 */
#[CoversClass(RateLimitGuard::class)]
class RateLimitGuardTest extends TestCase
{
    private InMemoryRateLimitStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryRateLimitStore();
    }

    public function testAllowsUnderLimit(): void
    {
        $guard = $this->makeGuard(limit: 3);

        $this->assertNull($guard->check($this->makeContext('plugin_a', 'sess1', '203.0.113.7')));
    }

    public function testRejectsAtCapPlusOneInWindow(): void
    {
        $guard = $this->makeGuard(limit: 2);
        $ctx = $this->makeContext('plugin_a', 'sess1', '203.0.113.7');

        $guard->check($ctx);
        $guard->check($ctx);
        $failure = $guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(429, $failure->httpStatus);
    }

    public function testOneClientSharesOneCounter_AcrossPluginIdsAndSessions(): void
    {
        $guard = $this->makeGuard(limit: 2);

        $this->assertNull($guard->check($this->makeContext('plugin_a', 'sess1', '203.0.113.7')));
        $this->assertNull($guard->check($this->makeContext('plugin_b', 'sess2', '203.0.113.7')));
        $failure = $guard->check($this->makeContext('invented-id', 'sess3', '203.0.113.7'));

        $this->assertNotNull($failure, 'a new plugin id or a new session must not start the count again');
        $this->assertSame(429, $failure->httpStatus);
    }

    public function testDifferentClients_HaveOwnCounters(): void
    {
        $guard = $this->makeGuard(limit: 1);

        $guard->check($this->makeContext('plugin_a', 'sess1', '203.0.113.7'));

        $this->assertNotNull($guard->check($this->makeContext('plugin_a', 'sess1', '203.0.113.7')));
        $this->assertNull($guard->check($this->makeContext('plugin_a', 'sess1', '198.51.100.9')));
    }

    public function testTheKeyCarriesNothingTheCallerChooses(): void
    {
        $keys = [];
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->method('increment')->willReturnCallback(static function (string $key) use (&$keys): int {
            $keys[] = $key;

            return 1;
        });
        $guard = new RateLimitGuard($store, $this->config(30));

        $guard->check($this->makeContext('plugin-chosen-by-caller', 'session-chosen-by-caller', '203.0.113.7'));

        $this->assertCount(1, $keys);
        $this->assertStringNotContainsString('plugin-chosen-by-caller', $keys[0]);
        $this->assertStringNotContainsString('session-chosen-by-caller', $keys[0]);
        $this->assertStringNotContainsString('203.0.113.7', $keys[0], 'the address is hashed, not stored');
        $this->assertStringContainsString(sha1('203.0.113.7'), $keys[0]);
    }

    public function testHasPrioritySixty(): void
    {
        $this->assertSame(60, $this->makeGuard(limit: 30)->getPriority());
    }

    private function makeGuard(int $limit): RateLimitGuard
    {
        return new RateLimitGuard($this->store, $this->config($limit));
    }

    private function config(int $limit): RateLimitConfigInterface
    {
        $config = $this->createStub(RateLimitConfigInterface::class);
        $config->method('getLimitForPlugin')->willReturn($limit);

        return $config;
    }

    private function makeContext(string $pluginModuleId, string $sessionId, string $clientIp): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: $pluginModuleId,
            csrfToken: null,
            sessionId: $sessionId,
            originHeader: null,
            refererHeader: null,
            clientIp: $clientIp,
        );
    }
}

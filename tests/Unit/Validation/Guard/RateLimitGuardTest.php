<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\RateLimit\ConfigurableRateLimitConfig;
use OxidEsales\PaymentBase\Validation\RateLimit\InMemoryRateLimitStore;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitConfigInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        $guard = $this->makeGuard(globalDefault: 3);
        $ctx = $this->makeContext('plugin_a', 'sess1');

        $this->assertNull($guard->check($ctx));
    }

    public function testRejectsAtCapPlusOneInWindow(): void
    {
        $guard = $this->makeGuard(globalDefault: 2);
        $ctx = $this->makeContext('plugin_a', 'sess1');

        $guard->check($ctx);
        $guard->check($ctx);
        $failure = $guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(429, $failure->httpStatus);
    }

    public function testCountersAreScopedByPluginModuleId(): void
    {
        $guard = $this->makeGuard(globalDefault: 2);
        $ctxA = $this->makeContext('plugin_a', 'sess1');
        $ctxB = $this->makeContext('plugin_b', 'sess1');

        $guard->check($ctxA);
        $guard->check($ctxA);
        // plugin_a is at limit; plugin_b is independent
        $this->assertNotNull($guard->check($ctxA), 'plugin_a should be blocked');
        $this->assertNull($guard->check($ctxB), 'plugin_b should still pass');
    }

    public function testRespectsPerPluginOverride(): void
    {
        $config = $this->createMock(RateLimitConfigInterface::class);
        $config->method('getLimitForPlugin')
            ->willReturnCallback(static fn (string $id): int => $id === 'acme_pay' ? 5 : 30);

        $guard = new RateLimitGuard($this->store, $config);
        $ctxAcme = $this->makeContext('acme_pay', 'sess1');
        $ctxStripe = $this->makeContext('oe_payments_stripe_wallet', 'sess1');

        for ($i = 0; $i < 5; $i++) {
            $guard->check($ctxAcme);
        }
        $this->assertNotNull($guard->check($ctxAcme), 'acme_pay at limit of 5 should be rejected');
        $this->assertNull($guard->check($ctxStripe), 'stripe still has 30-limit headroom');
    }

    public function testHasPrioritySixty(): void
    {
        $guard = $this->makeGuard(globalDefault: 30);

        $this->assertSame(60, $guard->getPriority());
    }

    private function makeGuard(int $globalDefault): RateLimitGuard
    {
        $config = new ConfigurableRateLimitConfig($globalDefault, []);

        return new RateLimitGuard($this->store, $config);
    }

    private function makeContext(string $pluginModuleId, string $sessionId): ValidationRequestContext
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
        );
    }
}

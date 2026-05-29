<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\SameOriginGuard;
use OxidEsales\PaymentBase\Validation\Guard\ShopUrlResolverInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SameOriginGuard::class)]
class SameOriginGuardTest extends TestCase
{
    private SameOriginGuard $guard;

    protected function setUp(): void
    {
        $resolver = $this->createMock(ShopUrlResolverInterface::class);
        $resolver->method('getShopUrl')->willReturn('https://shop.example.com');
        $this->guard = new SameOriginGuard($resolver);
    }

    public function testAcceptsMatchingOriginHeader(): void
    {
        $ctx = $this->makeContext(origin: 'https://shop.example.com', referer: null);

        $this->assertNull($this->guard->check($ctx));
    }

    public function testRejectsMismatchingOriginHeader(): void
    {
        $ctx = $this->makeContext(origin: 'https://evil.example.com', referer: null);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(403, $failure->httpStatus);
    }

    public function testFallsBackToRefererWhenOriginAbsent(): void
    {
        $ctx = $this->makeContext(origin: null, referer: 'https://shop.example.com/checkout');

        $this->assertNull($this->guard->check($ctx));
    }

    public function testRejectsMissingBothHeaders(): void
    {
        $ctx = $this->makeContext(origin: null, referer: null);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(403, $failure->httpStatus);
    }

    public function testHasPriorityForty(): void
    {
        $this->assertSame(40, $this->guard->getPriority());
    }

    private function makeContext(?string $origin, ?string $referer): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: 'test',
            csrfToken: null,
            sessionId: 'sess',
            originHeader: $origin,
            refererHeader: $referer,
        );
    }
}

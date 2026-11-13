<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxyClientIp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationRequestContext::class)]
class ValidationRequestContextTest extends TestCase
{
    public function testConstructorExposesAllFields(): void
    {
        $ctx = new ValidationRequestContext(
            method: 'POST',
            bodySize: 128,
            fields: ['firstName' => 'John'],
            pluginModuleId: 'acme_pay',
            csrfToken: 'tok123',
            sessionId: 'sess456',
            originHeader: 'https://shop.example.com',
            refererHeader: null,
            clientIp: '203.0.113.1',
        );

        $this->assertSame('POST', $ctx->getMethod());
        $this->assertSame(128, $ctx->getBodySize());
        $this->assertSame(['firstName' => 'John'], $ctx->getFields());
        $this->assertSame('acme_pay', $ctx->getPluginModuleId());
        $this->assertSame('tok123', $ctx->getCsrfToken());
        $this->assertSame('sess456', $ctx->getSessionId());
        $this->assertSame('https://shop.example.com', $ctx->getOriginHeader());
        $this->assertNull($ctx->getRefererHeader());
        $this->assertSame('203.0.113.1', $ctx->getClientIp());
        $this->assertSame(1, $ctx->getFieldCount());
    }

    public function testFieldCountMatchesFieldsArray(): void
    {
        $ctx = new ValidationRequestContext(
            method: 'POST',
            bodySize: 64,
            fields: ['a' => '1', 'b' => '2', 'c' => '3'],
            pluginModuleId: 'x',
            csrfToken: null,
            sessionId: null,
            originHeader: null,
            refererHeader: null,
            clientIp: '203.0.113.1',
        );

        $this->assertSame(3, $ctx->getFieldCount());
    }

    /**
     * Sprint 29 (VRL): the rate limit counts per visitor address, resolved like the webhook limiters'.
     */
    public function testFromRequest_TakesTheRemoteAddress_WhenNoProxyIsTrusted(): void
    {
        $ctx = ValidationRequestContext::fromRequest(
            new TrustedProxyClientIp(),
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '198.51.100.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99'],
            ['pluginModuleId' => 'p'],
        );

        $this->assertSame('198.51.100.4', $ctx->getClientIp(), 'a header from an untrusted caller is ignored');
    }

    public function testFromRequest_TakesTheProxyHeader_FromATrustedProxy(): void
    {
        $ctx = ValidationRequestContext::fromRequest(
            new TrustedProxyClientIp(['10.0.0.0/8']),
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 10.1.2.3'],
            ['pluginModuleId' => 'p'],
        );

        $this->assertSame('203.0.113.99', $ctx->getClientIp());
    }

    public function testFromRequest_WithoutARemoteAddress_HasAnEmptyClientIp(): void
    {
        $ctx = ValidationRequestContext::fromRequest(new TrustedProxyClientIp(), ['REQUEST_METHOD' => 'POST'], []);

        $this->assertSame('', $ctx->getClientIp());
    }
}

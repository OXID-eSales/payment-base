<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
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
        );

        $this->assertSame('POST', $ctx->getMethod());
        $this->assertSame(128, $ctx->getBodySize());
        $this->assertSame(['firstName' => 'John'], $ctx->getFields());
        $this->assertSame('acme_pay', $ctx->getPluginModuleId());
        $this->assertSame('tok123', $ctx->getCsrfToken());
        $this->assertSame('sess456', $ctx->getSessionId());
        $this->assertSame('https://shop.example.com', $ctx->getOriginHeader());
        $this->assertNull($ctx->getRefererHeader());
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
        );

        $this->assertSame(3, $ctx->getFieldCount());
    }
}

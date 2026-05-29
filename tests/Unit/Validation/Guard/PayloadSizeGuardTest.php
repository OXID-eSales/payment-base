<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\PayloadSizeGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadSizeGuard::class)]
class PayloadSizeGuardTest extends TestCase
{
    private PayloadSizeGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PayloadSizeGuard();
    }

    public function testRejectsBodyExceedingFourKilobytes(): void
    {
        $ctx = $this->makeContext(bodySize: 4097, fieldCount: 1);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(413, $failure->httpStatus);
    }

    public function testRejectsMoreThanThirtyTwoFields(): void
    {
        $ctx = $this->makeContext(bodySize: 10, fieldCount: 33);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(413, $failure->httpStatus);
    }

    public function testAcceptsExactlyFourKilobytes(): void
    {
        $ctx = $this->makeContext(bodySize: 4096, fieldCount: 1);

        $this->assertNull($this->guard->check($ctx));
    }

    public function testAcceptsExactlyThirtyTwoFields(): void
    {
        $ctx = $this->makeContext(bodySize: 10, fieldCount: 32);

        $this->assertNull($this->guard->check($ctx));
    }

    public function testHasPriorityTwenty(): void
    {
        $this->assertSame(20, $this->guard->getPriority());
    }

    private function makeContext(int $bodySize, int $fieldCount): ValidationRequestContext
    {
        $fields = array_fill(0, $fieldCount, 'x');
        $fields = array_combine(range(0, $fieldCount - 1), $fields);

        return new ValidationRequestContext(
            method: 'POST',
            bodySize: $bodySize,
            fields: $fields,
            pluginModuleId: 'test',
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );
    }
}

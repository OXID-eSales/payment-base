<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\GuardFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuardFailure::class)]
class GuardFailureTest extends TestCase
{
    public function testHttpStatusFactoryStoresStatus(): void
    {
        $failure = GuardFailure::httpStatus(405);

        $this->assertSame(405, $failure->httpStatus);
        $this->assertSame('', $failure->guardName);
    }

    public function testHttpStatusFactoryStoresGuardName(): void
    {
        $failure = GuardFailure::httpStatus(403, 'SameOriginGuard');

        $this->assertSame(403, $failure->httpStatus);
        $this->assertSame('SameOriginGuard', $failure->guardName);
    }
}

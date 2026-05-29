<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\ActiveSessionGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveSessionGuard::class)]
class ActiveSessionGuardTest extends TestCase
{
    private ActiveSessionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new ActiveSessionGuard();
    }

    public function testRejectsEmptySessionId(): void
    {
        $ctx = $this->makeContext('');

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(401, $failure->httpStatus);
    }

    public function testRejectsNullSessionId(): void
    {
        $ctx = $this->makeContext(null);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(401, $failure->httpStatus);
    }

    public function testAcceptsNonEmptySessionId(): void
    {
        $ctx = $this->makeContext('abc123');

        $this->assertNull($this->guard->check($ctx));
    }

    public function testHasPriorityThirty(): void
    {
        $this->assertSame(30, $this->guard->getPriority());
    }

    private function makeContext(?string $sessionId): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: 'test',
            csrfToken: null,
            sessionId: $sessionId,
            originHeader: null,
            refererHeader: null,
        );
    }
}

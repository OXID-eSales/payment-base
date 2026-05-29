<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\CsrfTokenGuard;
use OxidEsales\PaymentBase\Validation\Guard\SessionChallengeVerifierInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsrfTokenGuard::class)]
class CsrfTokenGuardTest extends TestCase
{
    private SessionChallengeVerifierInterface&MockObject $verifier;
    private CsrfTokenGuard $guard;

    protected function setUp(): void
    {
        $this->verifier = $this->createMock(SessionChallengeVerifierInterface::class);
        $this->guard = new CsrfTokenGuard($this->verifier);
    }

    public function testAcceptsWhenVerifierReturnsTrue(): void
    {
        $this->verifier->method('verify')->willReturn(true);
        $ctx = $this->makeContext('valid-token');

        $this->assertNull($this->guard->check($ctx));
    }

    public function testRejectsWhenVerifierReturnsFalse(): void
    {
        $this->verifier->method('verify')->willReturn(false);
        $ctx = $this->makeContext('bad-token');

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(403, $failure->httpStatus);
    }

    public function testRejectsNullCsrfToken(): void
    {
        $this->verifier->method('verify')->willReturn(false);
        $ctx = $this->makeContext(null);

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(403, $failure->httpStatus);
    }

    public function testHasPriorityFifty(): void
    {
        $this->assertSame(50, $this->guard->getPriority());
    }

    private function makeContext(?string $csrfToken): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: 'test',
            csrfToken: $csrfToken,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );
    }
}

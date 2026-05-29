<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\PostOnlyGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostOnlyGuard::class)]
class PostOnlyGuardTest extends TestCase
{
    private PostOnlyGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PostOnlyGuard();
    }

    public function testRejectsGet(): void
    {
        $ctx = $this->makeContext('GET');

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(405, $failure->httpStatus);
    }

    public function testAcceptsPost(): void
    {
        $ctx = $this->makeContext('POST');

        $this->assertNull($this->guard->check($ctx));
    }

    public function testHasPriorityTen(): void
    {
        $this->assertSame(10, $this->guard->getPriority());
    }

    private function makeContext(string $method): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: $method,
            bodySize: 0,
            fields: [],
            pluginModuleId: 'test',
            csrfToken: null,
            sessionId: null,
            originHeader: null,
            refererHeader: null,
        );
    }
}

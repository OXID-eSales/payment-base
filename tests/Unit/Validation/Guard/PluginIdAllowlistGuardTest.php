<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\Guard\ActiveModuleQueryInterface;
use OxidEsales\PaymentBase\Validation\Guard\PluginIdAllowlistGuard;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginIdAllowlistGuard::class)]
class PluginIdAllowlistGuardTest extends TestCase
{
    private ActiveModuleQueryInterface&MockObject $activeModuleQuery;
    private PluginIdAllowlistGuard $guard;

    protected function setUp(): void
    {
        $this->activeModuleQuery = $this->createMock(ActiveModuleQueryInterface::class);
        $this->guard = new PluginIdAllowlistGuard($this->activeModuleQuery);
    }

    public function testAcceptsActivePluginId(): void
    {
        $this->activeModuleQuery->method('isActive')->with('oe_payments_stripe_wallet')->willReturn(true);
        $ctx = $this->makeContext('oe_payments_stripe_wallet');

        $this->assertNull($this->guard->check($ctx));
    }

    public function testRejectsInactivePluginId(): void
    {
        $this->activeModuleQuery->method('isActive')->willReturn(false);
        $ctx = $this->makeContext('unknown_plugin');

        $failure = $this->guard->check($ctx);

        $this->assertNotNull($failure);
        $this->assertSame(422, $failure->httpStatus);
    }

    public function testHasPrioritySeventyenty(): void
    {
        $this->assertSame(70, $this->guard->getPriority());
    }

    private function makeContext(string $pluginModuleId): ValidationRequestContext
    {
        return new ValidationRequestContext(
            method: 'POST',
            bodySize: 10,
            fields: [],
            pluginModuleId: $pluginModuleId,
            csrfToken: null,
            sessionId: 'sess',
            originHeader: null,
            refererHeader: null,
        );
    }
}

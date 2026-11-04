<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\PhpStan;

use OxidEsales\PaymentBase\Core\Events\ModuleLifecycle;
use OxidEsales\PaymentBase\GraphQL\Admin\Controller\PaymentQueries;
use OxidEsales\PaymentBase\Tests\PhpStan\DeadCode\OxidEntryPointUsageProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The dead-code gate (sprint 17 DC-5) cannot see callers that live in a framework: the GraphQL schema
 * builder, the shop's `metadata.php` events and the template engine. This provider names them.
 */
final class OxidEntryPointUsageProviderTest extends TestCase
{
    private OxidEntryPointUsageProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new OxidEntryPointUsageProvider();
    }

    public function testGraphQliteQueryMethodIsAnEntryPoint(): void
    {
        $method = new ReflectionMethod(PaymentQueries::class, 'adminPaymentsAtRisk');

        $this->assertTrue($this->provider->isEntryPoint($method));
    }

    public function testMetadataEventHandlerIsAnEntryPoint(): void
    {
        $method = new ReflectionMethod(ModuleLifecycle::class, 'onActivate');

        $this->assertTrue($this->provider->isEntryPoint($method));
    }

    public function testPlainServiceMethodIsNotAnEntryPoint(): void
    {
        $method = new ReflectionMethod(self::class, 'testPlainServiceMethodIsNotAnEntryPoint');

        $this->assertFalse($this->provider->isEntryPoint($method));
    }

    public function testPrivateMethodOfAGraphQliteClassIsNotAnEntryPoint(): void
    {
        $method = new ReflectionMethod(OxidEntryPointUsageProvider::class, 'hasGraphQliteAttribute');

        $this->assertFalse($this->provider->isEntryPoint($method));
    }
}

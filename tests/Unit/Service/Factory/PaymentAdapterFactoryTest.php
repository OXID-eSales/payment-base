<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Factory;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactory;
use OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for StripeAdapterFactory (concrete implementation of PaymentAdapterFactory).
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactory
 * @covers \OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactory
 */
final class PaymentAdapterFactoryTest extends TestCase
{
    private StripeAdapterFactory $factory;
    private ModuleConfigurationService|MockObject $configurationService;
    private StripeClientFactory $clientFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationService = $this->createMock(ModuleConfigurationService::class);
        $this->configurationService
            ->method('getSecretKey')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        // getToken() is the actual method used by StripeClientFactory
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        // Use real StripeClientFactory since it's final and cannot be mocked
        $this->clientFactory = new StripeClientFactory($this->configurationService);

        $this->factory = new StripeAdapterFactory(
            $this->configurationService,
            $this->clientFactory
        );
    }

    public function testFactoryImplementsInterface(): void
    {
        $this->assertInstanceOf(PaymentAdapterFactoryInterface::class, $this->factory);
    }

    public function testFactoryExtendsAbstractClass(): void
    {
        $this->assertInstanceOf(PaymentAdapterFactory::class, $this->factory);
    }

    public function testCreateAdapterReturnsStripeAdapter(): void
    {
        $adapter = $this->factory->createAdapter('stripe');

        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testCreateAdapterThrowsExceptionForUnsupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment provider: unsupported_provider');

        $this->factory->createAdapter('unsupported_provider');
    }

    public function testCreateDefaultAdapterReturnsStripeAdapter(): void
    {
        $adapter = $this->factory->createDefaultAdapter();

        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testIsProviderSupportedReturnsTrueForStripe(): void
    {
        $this->assertTrue($this->factory->isProviderSupported('stripe'));
    }

    public function testIsProviderSupportedReturnsFalseForUnsupported(): void
    {
        $this->assertFalse($this->factory->isProviderSupported('paypal'));
        $this->assertFalse($this->factory->isProviderSupported('unzer'));
        $this->assertFalse($this->factory->isProviderSupported('unknown'));
    }

    public function testGetSupportedProvidersReturnsArray(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertIsArray($providers);
        $this->assertContains('stripe', $providers);
    }

    public function testGetSupportedProvidersContainsStripe(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertCount(1, $providers);
        $this->assertSame(['stripe'], $providers);
    }

    public function testFactoryCreatesAdapterWithProvidedConfiguration(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('getToken')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(true);

        $clientFactory = new StripeClientFactory($configService);
        $factory = new StripeAdapterFactory($configService, $clientFactory);

        $adapter = $factory->createAdapter('stripe');

        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testFactorySupportsTestMode(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('getToken')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(true);

        $clientFactory = new StripeClientFactory($configService);
        $testFactory = new StripeAdapterFactory($configService, $clientFactory);

        $adapter = $testFactory->createDefaultAdapter();
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testFactorySupportsLiveMode(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('getToken')->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(false);

        $clientFactory = new StripeClientFactory($configService);
        $liveFactory = new StripeAdapterFactory($configService, $clientFactory);

        $adapter = $liveFactory->createDefaultAdapter();
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testCreateAdapterIsCaseSensitive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // 'Stripe' (capitalized) should not match 'stripe'
        $this->factory->createAdapter('Stripe');
    }

    public function testStripeFactoryIsInCorrectNamespace(): void
    {
        // StripeAdapterFactory should be in Stripe namespace
        $reflectionClass = new \ReflectionClass(StripeAdapterFactory::class);
        $namespace = $reflectionClass->getNamespaceName();

        $this->assertStringContainsString('Stripe', $namespace);
    }

    public function testAbstractFactoryIsProviderAgnostic(): void
    {
        // The abstract PaymentAdapterFactory should be in Component namespace
        $reflectionClass = new \ReflectionClass(PaymentAdapterFactory::class);
        $namespace = $reflectionClass->getNamespaceName();

        $this->assertStringContainsString('Component', $namespace);
        $this->assertStringNotContainsString('Stripe\\', $namespace);
    }

    public function testFactoryReturnsProviderAgnosticInterface(): void
    {
        $adapter = $this->factory->createAdapter('stripe');
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testMultipleCallsCreateNewInstances(): void
    {
        $adapter1 = $this->factory->createAdapter('stripe');
        $adapter2 = $this->factory->createAdapter('stripe');

        // Each call should create a new instance
        $this->assertNotSame($adapter1, $adapter2);
    }

    public function testGetStripeClientReturnsClient(): void
    {
        $client = $this->factory->getStripeClient();

        $this->assertInstanceOf(\Stripe\StripeClient::class, $client);
    }

    public function testIsTestModeReturnsBool(): void
    {
        $this->assertTrue($this->factory->isTestMode());
    }
}

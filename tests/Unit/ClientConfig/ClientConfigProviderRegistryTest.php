<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\ClientConfigProviderRegistry;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;
use PHPUnit\Framework\TestCase;

final class ClientConfigProviderRegistryTest extends TestCase
{
    public function testResolvesAProviderById(): void
    {
        $stripe = new FakeClientConfigProvider('stripe', ['publishableKey' => 'pk_test_1']);
        $paypal = new FakeClientConfigProvider('paypal', ['clientId' => 'abc']);

        $registry = new ClientConfigProviderRegistry([$stripe, $paypal]);

        self::assertSame($paypal, $registry->get('paypal'));
        self::assertSame($stripe, $registry->get('stripe'));
    }

    public function testAcceptsAnyIterableSoATaggedIteratorWorks(): void
    {
        $stripe = new FakeClientConfigProvider('stripe', []);

        $registry = new ClientConfigProviderRegistry((static fn () => yield $stripe)());

        self::assertSame($stripe, $registry->get('stripe'));
    }

    public function testAnUnknownProviderIsAClientSafeError(): void
    {
        $registry = new ClientConfigProviderRegistry([new FakeClientConfigProvider('stripe', [])]);

        $this->expectException(UnknownClientConfigProvider::class);
        $this->expectExceptionMessage('Unknown payment provider "mollie"');

        $registry->get('mollie');
    }

    public function testTwoProvidersWithTheSameIdAreAConfigurationError(): void
    {
        $registry = new ClientConfigProviderRegistry([
            new FakeClientConfigProvider('stripe', []),
            new FakeClientConfigProvider('stripe', []),
        ]);

        $this->expectException(\LogicException::class);

        $registry->get('stripe');
    }
}

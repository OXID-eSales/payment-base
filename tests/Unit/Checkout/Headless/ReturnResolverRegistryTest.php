<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Checkout\Headless\ReturnResolverRegistry;
use OxidEsales\PaymentBase\Checkout\Headless\ReturnResolverRegistryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S6 — a provider's return resolver (the piece that translates
 * "what the PSP says on return" into a ReturnResolution) is registered by
 * provider name (`oe.payment.return_resolver`, attribute `provider`), so the
 * headless return mutation can hand it to CheckoutReturnResponder exactly as
 * the provider's Twig controller does.
 */
final class ReturnResolverRegistryTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(ReturnResolverRegistryInterface::class, new ReturnResolverRegistry([]));
    }

    public function testFindsAResolverByProviderName(): void
    {
        $stripe = $this->createMock(ReturnResolverInterface::class);
        $mollie = $this->createMock(ReturnResolverInterface::class);
        $registry = new ReturnResolverRegistry(['stripe' => $stripe, 'mollie' => $mollie]);

        self::assertSame($stripe, $registry->forProvider('stripe'));
        self::assertSame($mollie, $registry->forProvider('mollie'));
        self::assertNull($registry->forProvider('paypal'));
    }

    public function testProviderNamesAreCaseInsensitive(): void
    {
        $stripe = $this->createMock(ReturnResolverInterface::class);
        $registry = new ReturnResolverRegistry(['Stripe' => $stripe]);

        self::assertSame($stripe, $registry->forProvider('stripe'));
    }
}

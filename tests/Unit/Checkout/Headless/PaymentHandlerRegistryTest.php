<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistry;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The one-page checkout's handler for the shop's own payments: tagged
 * `oe.payment.handler` like a provider's, but no contract behind it.
 */
final class StandardLikeHandler implements PaymentHandlerInterface
{
    public function getId(): string
    {
        return 'standard';
    }

    public function getName(): string
    {
        return 'Standard';
    }

    public function supports(string $paymentMethodId): bool
    {
        return true;
    }

    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult
    {
        return PaymentHandlerResult::success();
    }

    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success();
    }

    public function getFrontendConfig(): array
    {
        return [];
    }
}

/**
 * Sprint 15 / S6 — every provider already tags its OPC handler
 * `oe.payment.handler`; this registry (payment-base's own, the OPC module has
 * one too) lets the headless checkout find the handler for a payment id or a
 * provider name without any new provider work.
 */
final class PaymentHandlerRegistryTest extends TestCase
{
    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(PaymentHandlerRegistryInterface::class, new PaymentHandlerRegistry([]));
    }

    public function testFindsTheHandlerThatSupportsAPaymentId(): void
    {
        $stripe = new FakePaymentHandler('stripe', ['oe_payments_stripe_wallet']);
        $mollie = new FakePaymentHandler('mollie', ['oe_payments_mollie']);
        $registry = new PaymentHandlerRegistry([$stripe, $mollie]);

        self::assertSame($mollie, $registry->forPaymentMethod('oe_payments_mollie'));
        self::assertSame($stripe, $registry->forPaymentMethod('oe_payments_stripe_wallet'));
        self::assertNull($registry->forPaymentMethod('oxidpayadvance'), 'a core payment is not contract-first');
    }
}

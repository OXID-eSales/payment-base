<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint I — red tests for PaymentPanelRegistry.
 *
 * The registry iterates tagged providers and returns the first one
 * that supports the current order/contract, by declared priority.
 */
final class PaymentPanelRegistryTest extends TestCase
{
    public function testReturnsNullWhenNoProvidersRegistered(): void
    {
        $registry = new PaymentPanelRegistry([]);

        self::assertNull($registry->resolveFor($this->context('oe_payments_stripe_wallet', 'stripe')));
    }

    public function testReturnsNullWhenNoProviderSupportsOrder(): void
    {
        $registry = new PaymentPanelRegistry([
            $this->provider(supports: false),
            $this->provider(supports: false),
        ]);

        self::assertNull($registry->resolveFor($this->context('oxidinvoice', null)));
    }

    public function testReturnsFirstSupportingProvider(): void
    {
        $expected = $this->provider(supports: true, name: 'stripe');

        $registry = new PaymentPanelRegistry([
            $this->provider(supports: false, name: 'paypal'),
            $expected,
            $this->provider(supports: true, name: 'other'),
        ]);

        $actual = $registry->resolveFor($this->context('oe_payments_stripe_wallet', 'stripe'));
        self::assertSame($expected, $actual);
    }

    public function testResolveByProviderNameReturnsTaggedProvider(): void
    {
        $stripe = $this->provider(supports: true, name: 'stripe');
        $paypal = $this->provider(supports: true, name: 'paypal');

        $registry = new PaymentPanelRegistry([$stripe, $paypal]);

        self::assertSame($stripe, $registry->resolveByProviderName('stripe'));
        self::assertSame($paypal, $registry->resolveByProviderName('paypal'));
        self::assertNull($registry->resolveByProviderName('unknown'));
    }

    private function context(string $paymentType, ?string $provider): PaymentPanelContext
    {
        return new PaymentPanelContext(
            orderId: 'order_1',
            paymentType: $paymentType,
            contract: $provider === null ? null : $this->contractMock($provider),
        );
    }

    private function contractMock(string $provider): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn($provider);
        return $contract;
    }

    private function provider(bool $supports, string $name = 'stub'): PaymentPanelProviderInterface
    {
        return new class ($supports, $name) implements PaymentPanelProviderInterface {
            public function __construct(private readonly bool $supports, private readonly string $name)
            {
            }

            public function getProviderName(): string
            {
                return $this->name;
            }

            public function supports(PaymentPanelContext $context): bool
            {
                return $this->supports;
            }

            public function build(PaymentPanelContext $context): PaymentPanelRenderable
            {
                return new PaymentPanelRenderable(
                    templatePath: '@stub/panel.html.twig',
                    viewData: [],
                    providerKey: $this->name,
                );
            }

            public function handleAction(string $action, array $request, PaymentPanelContext $context): void
            {
            }
        };
    }
}

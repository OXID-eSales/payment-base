<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Admin;

use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentComponent\Admin\PaymentAdminActionDispatcher;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentComponent\Admin\Panel\UnsupportedPaymentActionException;
use PHPUnit\Framework\TestCase;

/**
 * Sprint I — the admin controller's action dispatcher.
 *
 * Looks up the active provider for an order and delegates
 * action handling. Throws when no provider owns the order.
 */
final class PaymentAdminActionDispatcherTest extends TestCase
{
    public function testDelegatesActionToResolvedProvider(): void
    {
        $captured = [];
        $provider = new class ($captured) implements PaymentPanelProviderInterface {
            public function __construct(private array &$captured)
            {
            }

            public function getProviderName(): string
            {
                return 'stripe';
            }

            public function supports(PaymentPanelContext $context): bool
            {
                return true;
            }

            public function build(PaymentPanelContext $context): PaymentPanelRenderable
            {
                return new PaymentPanelRenderable('@x', [], 'stripe');
            }

            public function handleAction(string $action, array $request, PaymentPanelContext $context): void
            {
                $this->captured[] = [$action, $request, $context->orderId];
            }
        };

        $registry = $this->registryReturning($provider);
        $dispatcher = new PaymentAdminActionDispatcher($registry);

        $context = new PaymentPanelContext(orderId: 'o1', paymentType: 'oe_payments_stripe_wallet', contract: null);
        $dispatcher->dispatch('refund', ['amount' => 12.0], $context);

        self::assertSame([['refund', ['amount' => 12.0], 'o1']], $captured);
    }

    public function testThrowsWhenNoProviderMatches(): void
    {
        $registry = $this->registryReturning(null);
        $dispatcher = new PaymentAdminActionDispatcher($registry);

        $this->expectException(UnsupportedPaymentActionException::class);
        $dispatcher->dispatch(
            'refund',
            [],
            new PaymentPanelContext('o_unknown', 'oxidinvoice', null)
        );
    }

    private function registryReturning(?PaymentPanelProviderInterface $provider): PaymentPanelRegistryInterface
    {
        $mock = $this->createMock(PaymentPanelRegistryInterface::class);
        $mock->method('resolveFor')->willReturn($provider);
        return $mock;
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Admin\AdminRefusalFeedbackInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\PaymentAdminActionDispatcher;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Admin\Panel\UnsupportedPaymentActionException;
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

    public function testARefusalOfTheProvider_IsHandedToTheClassicFeedback_NotThrownAtTheController(): void
    {
        $shown = [];
        $feedback = new class ($shown) implements AdminRefusalFeedbackInterface {
            public function __construct(private array &$shown)
            {
            }

            public function show(AdminActionRefusedException $refusal): void
            {
                $this->shown[] = [$refusal->refusalCode(), $refusal->getMessage()];
            }
        };
        $dispatcher = new PaymentAdminActionDispatcher($this->registryReturning($this->throwing(new AdminActionRefusedException('too_small', 'Too small.'))), $feedback);

        $dispatcher->dispatch('refund', [], new PaymentPanelContext('o1', 'oe_payments_stripe_wallet', null));

        self::assertSame([['too_small', 'Too small.']], $shown);
    }

    public function testARefusalWithoutAFeedbackSink_IsStillSwallowed(): void
    {
        $dispatcher = new PaymentAdminActionDispatcher($this->registryReturning($this->throwing(new AdminActionRefusedException('too_small', 'Too small.'))));

        $dispatcher->dispatch('refund', [], new PaymentPanelContext('o1', 'oe_payments_stripe_wallet', null));

        $this->addToAssertionCount(1);
    }

    public function testAnyOtherProviderException_StillPropagates(): void
    {
        $dispatcher = new PaymentAdminActionDispatcher($this->registryReturning($this->throwing(new \RuntimeException('boom'))));

        $this->expectException(\RuntimeException::class);
        $dispatcher->dispatch('refund', [], new PaymentPanelContext('o1', 'oe_payments_stripe_wallet', null));
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

    private function throwing(\Throwable $failure): PaymentPanelProviderInterface
    {
        return new class ($failure) implements PaymentPanelProviderInterface {
            public function __construct(private readonly \Throwable $failure)
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
                throw $this->failure;
            }
        };
    }

    private function registryReturning(?PaymentPanelProviderInterface $provider): PaymentPanelRegistryInterface
    {
        $mock = $this->createMock(PaymentPanelRegistryInterface::class);
        $mock->method('resolveFor')->willReturn($provider);
        return $mock;
    }
}

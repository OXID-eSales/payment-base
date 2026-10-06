<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Subscriber;

use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\GraphQL\Storefront\Basket\Event\BeforePlaceOrder;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistry;
use OxidEsales\PaymentBase\GraphQL\Exception\ContractFirstPaymentCheckout;
use OxidEsales\PaymentBase\GraphQL\Subscriber\RefusePlaceOrderForContractFirstPayments;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless\FakePaymentHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TheCodingMachine\GraphQLite\Types\ID;

final class RowWithPayment extends UserBasket
{
    public function __construct(private readonly string $paymentId)
    {
    }

    public function getFieldData(string $field): mixed
    {
        return $field === 'oegql_paymentid' ? $this->paymentId : null;
    }
}

final class TestableRefuser extends RefusePlaceOrderForContractFirstPayments
{
    /** @var array<string, UserBasket> */
    public array $rows = [];

    protected function loadUserBasket(string $basketId): ?UserBasket
    {
        return $this->rows[$basketId] ?? null;
    }
}

/**
 * Sprint 15 / S6 — Option B: core's `placeOrder` is not used for contract-first
 * payments. A client that calls it anyway for a basket paying with Stripe /
 * Mollie / PayPal gets a clear error naming the mutation to call instead -
 * never a silently different checkout.
 */
final class RefusePlaceOrderForContractFirstPaymentsTest extends TestCase
{
    private TestableRefuser $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new TestableRefuser(new PaymentHandlerRegistry([
            new FakePaymentHandler('stripe', ['oe_payments_stripe_wallet']),
        ]));
    }

    public function testSubscribesToBeforePlaceOrder(): void
    {
        self::assertInstanceOf(EventSubscriberInterface::class, $this->subscriber);
        self::assertArrayHasKey(BeforePlaceOrder::class, RefusePlaceOrderForContractFirstPayments::getSubscribedEvents());
    }

    public function testLetsACorePaymentThrough(): void
    {
        $this->subscriber->rows['ub-1'] = new RowWithPayment('oxidpayadvance');

        $this->subscriber->onBeforePlaceOrder(new BeforePlaceOrder(new ID('ub-1')));

        $this->addToAssertionCount(1);
    }

    public function testLetsAnUnknownBasketThroughSoCoreReportsItItself(): void
    {
        $this->subscriber->onBeforePlaceOrder(new BeforePlaceOrder(new ID('missing')));

        $this->addToAssertionCount(1);
    }

    public function testRefusesAContractFirstPaymentNamingTheMutationToCall(): void
    {
        $this->subscriber->rows['ub-1'] = new RowWithPayment('oe_payments_stripe_wallet');

        try {
            $this->subscriber->onBeforePlaceOrder(new BeforePlaceOrder(new ID('ub-1')));
            self::fail('contract-first payments do not go through core placeOrder');
        } catch (ContractFirstPaymentCheckout $e) {
            self::assertStringContainsString('stripeCheckoutStart', $e->getMessage());
            self::assertStringContainsString('oe_payments_stripe_wallet', $e->getMessage());
            self::assertSame('requesterror', $e->getCategory());
        }
    }
}

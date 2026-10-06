<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL;

use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Storefront\Basket\Event\BeforePlaceOrder;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\PaymentHandlerRegistryInterface;
use OxidEsales\PaymentBase\Checkout\Headless\ReturnResolverRegistryInterface;
use OxidEsales\PaymentBase\GraphQL\Exception\ContractFirstPaymentCheckout;
use OxidEsales\PaymentBase\GraphQL\Subscriber\RefusePlaceOrderForContractFirstPayments;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * Sprint 15 / S6 against the real container: the headless services compile
 * and resolve, the providers' tagged handlers are collected, and the
 * storefront's BeforePlaceOrder really is refused for a basket paying with a
 * contract-first payment while a core payment passes. The subscriber is
 * invoked directly (the storefront's own BasketAuthorization subscriber on
 * the same event would demand a JWT first); its registration on the event
 * is pinned by the unit test.
 *
 * Needs graphql-storefront (the event class and the OEGQL_PAYMENTID column);
 * skipped where it is not installed.
 */
#[Group('integration')]
final class HeadlessWiringTest extends IntegrationTestCase
{
    use CheckoutFixture;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(BeforePlaceOrder::class)) {
            self::markTestSkipped('graphql-storefront is not installed');
        }
    }

    public function testTheHeadlessServicesResolveFromTheContainer(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        self::assertInstanceOf(HeadlessCheckoutServiceInterface::class, $container->get(HeadlessCheckoutServiceInterface::class));
        self::assertInstanceOf(PaymentHandlerRegistryInterface::class, $container->get(PaymentHandlerRegistryInterface::class));
        self::assertInstanceOf(ReturnResolverRegistryInterface::class, $container->get(ReturnResolverRegistryInterface::class));
    }

    public function testCorePlaceOrderIsLeftAloneForACorePayment(): void
    {
        $user = $this->createFixtureUser('gql');
        $basketId = $this->persistUserBasketPayingWith($user->getId(), 'oxidpayadvance');

        $this->guard()->onBeforePlaceOrder(new BeforePlaceOrder(new ID($basketId)));

        $this->addToAssertionCount(1);
    }

    public function testCorePlaceOrderIsRefusedForAContractFirstPayment(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var PaymentHandlerRegistryInterface $registry */
        $registry = $container->get(PaymentHandlerRegistryInterface::class);
        $paymentId = $this->firstContractFirstPaymentId($registry);
        if ($paymentId === null) {
            self::markTestSkipped('no provider module with a tagged payment handler is active in this shop');
        }

        $user = $this->createFixtureUser('gqlcf');
        $basketId = $this->persistUserBasketPayingWith($user->getId(), $paymentId);

        $this->expectException(ContractFirstPaymentCheckout::class);

        $this->guard()->onBeforePlaceOrder(new BeforePlaceOrder(new ID($basketId)));
    }

    private function guard(): RefusePlaceOrderForContractFirstPayments
    {
        /** @var RefusePlaceOrderForContractFirstPayments $guard */
        $guard = ContainerFactory::getInstance()->getContainer()->get(RefusePlaceOrderForContractFirstPayments::class);

        return $guard;
    }

    private function persistUserBasketPayingWith(string $userId, string $paymentId): string
    {
        $row = oxNew(UserBasket::class);
        $row->setId('e2e_gql_' . substr(md5(uniqid('', true)), 0, 8));
        $row->oxuserbaskets__oxuserid = new Field($userId, Field::T_RAW);
        $row->oxuserbaskets__oxtitle = new Field('graphql-checkout', Field::T_RAW);
        $row->oxuserbaskets__oxpublic = new Field(0, Field::T_RAW);
        $row->oxuserbaskets__oegql_paymentid = new Field($paymentId, Field::T_RAW);
        $row->save();

        return (string) $row->getId();
    }

    private function firstContractFirstPaymentId(PaymentHandlerRegistryInterface $registry): ?string
    {
        foreach (['oe_payments_stripe_wallet', 'oe_payments_mollie', 'oxidpaypal', 'oe_payments_paypal'] as $candidate) {
            if ($registry->isContractFirst($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

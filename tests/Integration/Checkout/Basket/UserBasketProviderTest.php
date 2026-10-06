<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S1 against the real shop: an `oxuserbaskets` row (what the
 * GraphQL Storefront persists) becomes the same calculated Basket the Twig
 * checkout would have in the session, and OxidShopOrderService finalizes an
 * order from it with NOTHING in the session.
 *
 * Fixtures from {@see CheckoutFixture}; every write is rolled back by
 * IntegrationTestCase.
 */
#[Group('integration')]
final class UserBasketProviderTest extends IntegrationTestCase
{
    use CheckoutFixture;

    public function tearDown(): void
    {
        unset($_POST['sDeliveryAddressMD5'], $_REQUEST['sDeliveryAddressMD5']);
        parent::tearDown();
    }

    public function testTheUserBasketRowBecomesTheSameBasketTheSessionCheckoutWouldHave(): void
    {
        $user = $this->createFixtureUser('ub');
        $this->createFixturePayment();
        $article = $this->createFixtureArticle();
        $userBasket = $this->persistUserBasket($user, $article->getId(), 2);

        $expected = $this->sessionStyleBasket($user, $article->getId(), 2);

        $basket = $this->provider()->basketFor($this->headlessRequest($user, $userBasket->getId()));

        self::assertInstanceOf(Basket::class, $basket);
        self::assertSame(2, (int) $basket->getItemsCount());
        self::assertSame(self::PAYMENT_ID, $basket->getPaymentId());
        self::assertSame(self::DELIVERY_SET, $basket->getShippingId(), 'resolved from the delivery sets the user may use');
        self::assertEqualsWithDelta(
            $expected->getPrice()->getBruttoPrice(),
            $basket->getPrice()->getBruttoPrice(),
            0.001,
            'the order amount must be what the storefront showed the shopper'
        );
        self::assertSame($user->getId(), $basket->getBasketUser()->getId());
    }

    public function testAnOrderIsFinalizedFromTheUserBasketWithNothingInTheSession(): void
    {
        $user = $this->createFixtureUser('ubo');
        $this->createFixturePayment();
        $article = $this->createFixtureArticle();
        $userBasket = $this->persistUserBasket($user, $article->getId(), 1);
        $expected = $this->sessionStyleBasket($user, $article->getId(), 1);

        // Headless: no session basket, no posted address hash.
        Registry::getSession()->setBasket(oxNew(Basket::class));
        Registry::getSession()->setVariable('sess_challenge', md5(uniqid((string) mt_rand(), true)));

        /** @var ShopOrderServiceInterface $service */
        $service = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);
        $response = $service->createOrder($this->headlessRequest($user, $userBasket->getId()));

        $order = oxNew(Order::class);
        self::assertTrue($order->load($response->orderId));
        self::assertSame('NOT_FINISHED', $order->getFieldData('oxtransstatus'));
        self::assertSame(self::PAYMENT_ID, $order->getFieldData('oxpaymenttype'));
        self::assertSame($user->getId(), $order->getFieldData('oxuserid'));
        self::assertEqualsWithDelta(
            $expected->getPrice()->getBruttoPrice(),
            (float) $order->getFieldData('oxtotalordersum'),
            0.001
        );
    }

    private function provider(): CheckoutBasketProviderInterface
    {
        /** @var CheckoutBasketProviderInterface $provider */
        $provider = ContainerFactory::getInstance()->getContainer()->get(CheckoutBasketProviderInterface::class);

        return $provider;
    }

    private function headlessRequest(User $user, string $basketId): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'headless',
            userId: (string) $user->getId(),
            paymentId: self::PAYMENT_ID,
            initialStatus: 'NOT_FINISHED',
            basketId: $basketId,
        );
    }

    private function persistUserBasket(User $user, string $articleId, int $amount): UserBasket
    {
        $userBasket = oxNew(UserBasket::class);
        $userBasket->setId('e2e_ub_' . substr(md5(uniqid('', true)), 0, 8));
        $userBasket->oxuserbaskets__oxuserid = new Field($user->getId(), Field::T_RAW);
        $userBasket->oxuserbaskets__oxtitle = new Field('graphql-checkout', Field::T_RAW);
        $userBasket->oxuserbaskets__oxpublic = new Field(0, Field::T_RAW);
        $userBasket->save();
        $userBasket->addItemToBasket($articleId, $amount);

        return $userBasket;
    }

    private function sessionStyleBasket(User $user, string $articleId, int $amount): Basket
    {
        $basket = oxNew(Basket::class);
        $basket->setBasketUser($user);
        $basket->addToBasket($articleId, $amount);
        $basket->setPayment(self::PAYMENT_ID);
        $basket->setShipping(self::DELIVERY_SET);
        $basket->calculateBasket(true);

        return $basket;
    }
}

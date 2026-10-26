<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use OxidEsales\PaymentBase\Tests\Integration\Support\DeferredOrderMailFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * STRP-176 — the order payment-base creates before the customer pays at the
 * PSP sends no "thank you for your order"; core's rendering of it is stored.
 * Real mail through msmtp into Mailpit, each test with its own customer
 * address (the fixture user's), so the shared inbox is only ever queried.
 */
#[Group('integration')]
#[Group('mailpit')]
final class OxidShopOrderServiceDeferredMailTest extends IntegrationTestCase
{
    use CheckoutFixture;
    use DeferredOrderMailFixture;

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDeferredOrderMails();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->tearDownDeferredOrderMails();
    }

    public function testCreateOrder_SendsNoMailAndStoresTheCustomerAndOwnerMail(): void
    {
        $user = $this->checkoutUser('defer');

        $orderId = $this->remember($this->createOrderFor($user));

        $rows = $this->storedMails($orderId);
        self::assertSame(['customer', 'owner'], array_column($rows, 'OXKIND'));
        self::assertSame([[$this->addressOf($user), 'Bill-First Bill-Last']], json_decode($rows[0]['OXRECIPIENTS'], true));
        $orderNr = (string) $this->loadOrder($orderId)->getFieldData('oxordernr');
        self::assertStringContainsString('(#' . $orderNr . ')', $rows[0]['OXSUBJECT']);
        self::assertStringContainsString('Checkout fixture article', $rows[0]['OXBODY'], 'rendered with the real basket');
        self::assertNotSame('', $rows[1]['OXBODY']);

        // A positive control in the next test proves mail does arrive; give
        // Mailpit the same window before calling it absent.
        self::assertSame(0, $this->mailpit->waitForCountTo($this->addressOf($user), 1, 3000));
    }

    public function testFinalizeOrder_ForAnOrderPaymentBaseDidNotCreate_StillMailsAtOnce(): void
    {
        // Invoice, COD, prepayment: core's checkout, untouched.
        $user = $this->checkoutUser('direct');
        Registry::getSession()->setVariable('sess_challenge', md5(uniqid((string) mt_rand(), true)));

        $order = oxNew(Order::class);
        $state = $order->finalizeOrder(Registry::getSession()->getBasket(), $user);

        self::assertSame(Order::ORDER_STATE_OK, $state);
        self::assertSame([], $this->storedMails($this->remember((string) $order->getId())));
        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
    }

    public function testCancellingTheAbandonedCheckout_DropsItsStoredMails(): void
    {
        $user = $this->checkoutUser('abandon');
        Registry::getSession()->setVariable('sess_challenge', md5(uniqid((string) mt_rand(), true)));
        $service = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);
        $orderId = $service->createOrder(new CreateOrderRequest(
            sessionId: (string) Registry::getSession()->getId(),
            userId: $user->getId(),
            paymentId: self::PAYMENT_ID,
            initialStatus: 'NOT_FINISHED',
        ))->orderId;
        $this->remember($orderId);
        self::assertCount(2, $this->storedMails($orderId));

        self::assertTrue($service->deleteNotFinishedOrder($orderId));

        self::assertSame([], $this->storedMails($orderId));
    }

    public function testDeletingTheOrder_DropsItsStoredMails(): void
    {
        $orderId = $this->remember($this->createOrderFor($this->checkoutUser('delete')));
        self::assertCount(2, $this->storedMails($orderId));

        self::assertTrue($this->loadOrder($orderId)->delete());

        self::assertSame([], $this->storedMails($orderId));
    }

    private function checkoutUser(string $suffix): User
    {
        $user = $this->createFixtureUser('strp176_' . $suffix);
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        return $user;
    }
}

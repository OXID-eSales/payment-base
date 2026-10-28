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
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use OxidEsales\PaymentBase\Tests\Integration\Support\DeferredOrderMailFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * STRP-176 — the order payment-base creates before the customer pays at the
 * PSP sends no "thank you for your order"; core's rendering of it waits on the
 * contract row as "pending". Real mail through msmtp into Mailpit, each test
 * with its own customer address (the fixture user's), so the shared inbox is
 * only ever queried.
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

    public function testCreateOrder_SendsNoMailAndKeepsCustomerAndOwnerMailOnTheContract(): void
    {
        $user = $this->checkoutUser('defer');

        [$orderId, $contractId] = $this->createOrderOnContract($user);

        $kept = $this->orderMailsOn($contractId);
        self::assertSame('pending', $kept['state']);
        self::assertNull($kept['sentAt']);
        self::assertSame(['customer', 'owner'], array_column($kept['mails'], 'kind'));
        self::assertSame([[$this->addressOf($user), 'Bill-First Bill-Last']], $kept['mails'][0]['recipients']);
        $orderNr = (string) $this->loadOrder($orderId)->getFieldData('oxordernr');
        self::assertStringContainsString('(#' . $orderNr . ')', $kept['mails'][0]['subject']);
        self::assertStringContainsString('Checkout fixture article', $kept['mails'][0]['body'], 'rendered with the real basket');
        self::assertStringContainsString('€', $kept['mails'][0]['body'], 'the latin1 table keeps every character');
        self::assertNotSame('', $kept['mails'][1]['body']);

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
        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
    }

    public function testCreateOrder_WithoutAContract_SendsTheMailAtOnce(): void
    {
        // Nothing to keep the mail on: better before the payment than never.
        $user = $this->checkoutUser('nocon');

        $this->createOrderFor($user);

        self::assertSame(1, $this->mailpit->waitForCountTo($this->addressOf($user), 1));
    }

    public function testCancellingTheAbandonedCheckout_DropsItsWaitingMails(): void
    {
        [$orderId, $contractId] = $this->createOrderOnContract($this->checkoutUser('abandon'));
        self::assertSame('pending', $this->orderMailsOn($contractId)['state']);

        self::assertTrue($this->orderService()->deleteNotFinishedOrder($orderId));

        self::assertSame(['state' => 'dropped', 'mails' => [], 'sentAt' => null], $this->orderMailsOn($contractId));
    }

    public function testDeletingTheOrder_DropsItsWaitingMails(): void
    {
        [$orderId, $contractId] = $this->createOrderOnContract($this->checkoutUser('delete'));

        self::assertTrue($this->loadOrder($orderId)->delete());

        self::assertSame(['state' => 'dropped', 'mails' => [], 'sentAt' => null], $this->orderMailsOn($contractId));
    }

    private function checkoutUser(string $suffix): User
    {
        $user = $this->createFixtureUser('strp176_' . $suffix);
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        return $user;
    }
}

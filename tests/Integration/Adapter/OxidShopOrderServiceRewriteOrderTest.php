<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Model\BaseModel;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderRewriterInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistry;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use OxidEsales\PaymentBase\Tests\Integration\Support\DeferredOrderMailFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * OPC-175 / S2c — the customer changes the payment method (or the basket)
 * of the attempt they already have open: the SAME order is rewritten from the
 * current basket. Same order id, same order number, same contract (one order,
 * one contract); new payment, new lines, new totals; stock and vouchers follow
 * the basket; the order mails are rendered again and kept on the contract.
 */
#[Group('integration')]
#[Group('mailpit')]
final class OxidShopOrderServiceRewriteOrderTest extends IntegrationTestCase
{
    use CheckoutFixture;
    use DeferredOrderMailFixture;

    private const SECOND_PAYMENT_ID = 'e2e_sa_payment_two';
    private const SECOND_PAYMENT_TITLE = 'Second fixture payment';

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpDeferredOrderMails();
    }

    public function tearDown(): void
    {
        Registry::getSession()->deleteVariable(OpenCheckoutAttemptRegistry::SESSION_KEY);
        parent::tearDown();
        $this->tearDownDeferredOrderMails();
    }

    public function testRewriteOrder_KeepsIdAndNumber_TakesTheNewPaymentLinesAndTotals(): void
    {
        $user = $this->checkoutUser('rewrite');
        [$orderId, $contractId] = $this->createOrderOnContract($user);
        $orderNr = (string) $this->loadOrder($orderId)->getFieldData('oxordernr');
        $articleId = $this->onlyArticleIdOf($orderId);
        self::assertSame(99.0, $this->stockOf($articleId), 'the first order took one unit');

        $basket = $this->changeBasket(amount: 2, paymentId: $this->createSecondPayment());
        $response = $this->rewriter()->rewriteOrder($orderId, $this->request($user, self::SECOND_PAYMENT_ID, $contractId));

        $order = $this->loadOrder($orderId);
        self::assertSame($orderId, $response->orderId);
        self::assertSame($orderNr, (string) $order->getFieldData('oxordernr'));
        self::assertSame(self::SECOND_PAYMENT_ID, $order->getFieldData('oxpaymenttype'));
        self::assertSame(2.5, (float) $order->getFieldData('oxpaycost'));
        self::assertSame($basket->getPrice()->getBruttoPrice(), (float) $order->getFieldData('oxtotalordersum'));
        self::assertSame('NOT_FINISHED', $order->getFieldData('oxtransstatus'));
        self::assertSame([[$articleId, 2.0]], $this->orderLines($orderId), 'the old line is replaced, not kept');
        self::assertSame(98.0, $this->stockOf($articleId), 'old line restocked, new line taken');
    }

    public function testRewriteOrder_RendersTheMailsAgainOnTheContractAndSendsNothing(): void
    {
        $user = $this->checkoutUser('remail');
        [$orderId, $contractId] = $this->createOrderOnContract($user);

        $this->changeBasket(amount: 1, paymentId: $this->createSecondPayment());
        $this->rewriter()->rewriteOrder($orderId, $this->request($user, self::SECOND_PAYMENT_ID, $contractId));

        $kept = $this->orderMailsOn($contractId);
        self::assertSame('pending', $kept['state']);
        self::assertSame(['customer', 'owner'], array_column($kept['mails'], 'kind'));
        self::assertStringContainsString(self::SECOND_PAYMENT_TITLE, $kept['mails'][0]['body']);
        self::assertSame(0, $this->mailpit->waitForCountTo($this->addressOf($user), 1, 3000));
    }

    public function testRewriteOrder_TheVoucherStaysOnTheOrder(): void
    {
        $user = $this->checkoutUser('voucher');
        $voucherNr = $this->createVoucher();
        Registry::getSession()->getBasket()->addVoucher($voucherNr);
        Registry::getSession()->getBasket()->calculateBasket(true);
        [$orderId, $contractId] = $this->createOrderOnContract($user);
        $this->rememberOpenAttempt($contractId);
        self::assertSame([$orderId], $this->voucherHolders($voucherNr));

        $this->changeBasket(amount: 1, paymentId: $this->createSecondPayment());
        $this->rewriter()->rewriteOrder($orderId, $this->request($user, self::SECOND_PAYMENT_ID, $contractId));

        self::assertSame([$orderId], $this->voucherHolders($voucherNr));
        self::assertSame(5.0, (float) $this->loadOrder($orderId)->getFieldData('oxvoucherdiscount'));
    }

    public function testRewriteOrder_AVoucherRemovedFromTheBasketIsReleased(): void
    {
        $user = $this->checkoutUser('unvoucher');
        $voucherNr = $this->createVoucher();
        Registry::getSession()->getBasket()->addVoucher($voucherNr);
        Registry::getSession()->getBasket()->calculateBasket(true);
        [$orderId, $contractId] = $this->createOrderOnContract($user);
        $this->rememberOpenAttempt($contractId);

        $basket = Registry::getSession()->getBasket();
        foreach (array_keys($basket->getVouchers()) as $voucherId) {
            $basket->removeVoucher($voucherId);
        }
        $this->changeBasket(amount: 1, paymentId: $this->createSecondPayment());
        $this->rewriter()->rewriteOrder($orderId, $this->request($user, self::SECOND_PAYMENT_ID, $contractId));

        self::assertSame([], $this->voucherHolders($voucherNr));
        self::assertSame(0.0, (float) $this->loadOrder($orderId)->getFieldData('oxvoucherdiscount'));
    }

    public function testRewriteOrder_AnOrderThatIsNotOpen_IsRefused(): void
    {
        $user = $this->checkoutUser('closed');

        try {
            $this->rewriter()->rewriteOrder('no-such-order', $this->request($user, self::PAYMENT_ID, 'no-contract'));
            self::fail('a missing order must not be rewritten');
        } catch (\OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException $e) {
            self::assertSame('order_not_rewritable', $e->getErrorCode());
        }
    }

    private function rewriter(): ShopOrderRewriterInterface
    {
        /** @var ShopOrderRewriterInterface $rewriter */
        $rewriter = ContainerFactory::getInstance()->getContainer()->get(ShopOrderRewriterInterface::class);

        return $rewriter;
    }

    private function request(User $user, string $paymentId, string $contractId): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: (string) Registry::getSession()->getId(),
            userId: (string) $user->getId(),
            paymentId: $paymentId,
            metadata: ['contract_id' => $contractId],
            initialStatus: 'NOT_FINISHED',
        );
    }

    private function checkoutUser(string $suffix): User
    {
        $user = $this->createFixtureUser('opc175_' . $suffix);
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        return $user;
    }

    private function changeBasket(float $amount, string $paymentId): Basket
    {
        $basket = Registry::getSession()->getBasket();
        foreach ($basket->getContents() as $itemKey => $item) {
            $basket->addToBasket($item->getProductId(), $amount, null, null, true, false, $itemKey);
        }
        $basket->setPayment($paymentId);
        $basket->calculateBasket(true);

        return $basket;
    }

    /** The open attempt the session holds, as EarlyOrderCreationHandler records it. */
    private function rememberOpenAttempt(string $contractId): void
    {
        Registry::getSession()->setVariable(OpenCheckoutAttemptRegistry::SESSION_KEY, $contractId);
    }

    private function createSecondPayment(): string
    {
        $payment = oxNew(Payment::class);
        $payment->setId(self::SECOND_PAYMENT_ID);
        $payment->oxpayments__oxactive = new Field(1, Field::T_RAW);
        $payment->oxpayments__oxdesc = new Field(self::SECOND_PAYMENT_TITLE, Field::T_RAW);
        $payment->oxpayments__oxaddsum = new Field(2.5, Field::T_RAW);
        $payment->oxpayments__oxaddsumtype = new Field('abs', Field::T_RAW);
        $payment->oxpayments__oxfromboni = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxfromamount = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxtoamount = new Field(1000000, Field::T_RAW);
        $payment->save();

        $assignment = oxNew(BaseModel::class);
        $assignment->init('oxobject2payment');
        $assignment->oxobject2payment__oxpaymentid = new Field(self::SECOND_PAYMENT_ID, Field::T_RAW);
        $assignment->oxobject2payment__oxobjectid = new Field(self::DELIVERY_SET, Field::T_RAW);
        $assignment->oxobject2payment__oxtype = new Field('oxdelset', Field::T_RAW);
        $assignment->save();

        return self::SECOND_PAYMENT_ID;
    }

    /** One absolute 5.00 voucher, its own series. */
    private function createVoucher(): string
    {
        $seriesId = 'opc175_ser_' . substr(md5(uniqid('', true)), 0, 8);
        $series = oxNew(BaseModel::class);
        $series->init('oxvoucherseries');
        $series->setId($seriesId);
        $series->oxvoucherseries__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);
        $series->oxvoucherseries__oxserienr = new Field('OPC175', Field::T_RAW);
        $series->oxvoucherseries__oxdiscount = new Field(5.0, Field::T_RAW);
        $series->oxvoucherseries__oxdiscounttype = new Field('absolute', Field::T_RAW);
        $series->oxvoucherseries__oxallowsameseries = new Field(1, Field::T_RAW);
        $series->oxvoucherseries__oxallowotherseries = new Field(1, Field::T_RAW);
        $series->oxvoucherseries__oxallowuseanother = new Field(1, Field::T_RAW);
        $series->oxvoucherseries__oxminimumvalue = new Field(0, Field::T_RAW);
        $series->save();

        $voucherNr = 'OPC175-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $voucher = oxNew(BaseModel::class);
        $voucher->init('oxvouchers');
        $voucher->setId('opc175_vou_' . substr(md5(uniqid('', true)), 0, 8));
        $voucher->oxvouchers__oxvoucherserieid = new Field($seriesId, Field::T_RAW);
        $voucher->oxvouchers__oxvouchernr = new Field($voucherNr, Field::T_RAW);
        $voucher->save();

        return $voucherNr;
    }

    /** @return list<string> */
    private function voucherHolders(string $voucherNr): array
    {
        /** @var list<string> $ids */
        $ids = DatabaseProvider::getDb()->getCol(
            "SELECT OXORDERID FROM oxvouchers WHERE OXVOUCHERNR = ? AND OXORDERID <> ''",
            [$voucherNr]
        );

        return $ids;
    }

    private function onlyArticleIdOf(string $orderId): string
    {
        return (string) DatabaseProvider::getDb()->getOne(
            'SELECT OXARTID FROM oxorderarticles WHERE OXORDERID = ?',
            [$orderId]
        );
    }

    /** @return list<array{0: string, 1: float}> */
    private function orderLines(string $orderId): array
    {
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_NUM)->getAll(
            'SELECT OXARTID, OXAMOUNT FROM oxorderarticles WHERE OXORDERID = ?',
            [$orderId]
        );

        return array_map(static fn (array $row): array => [(string) $row[0], (float) $row[1]], $rows);
    }

    private function stockOf(string $articleId): float
    {
        return (float) DatabaseProvider::getDb()->getOne('SELECT OXSTOCK FROM oxarticles WHERE OXID = ?', [$articleId]);
    }
}

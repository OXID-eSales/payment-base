<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Model\BaseModel;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 10 (2026-09-23) — proves the seam wired in Story 3: a shopper who
 * ships to their billing address gets the billing address copied into the
 * OXDEL* columns of the order OxidShopOrderService::createOrder() persists,
 * while a shopper who selected a separate delivery address keeps it.
 *
 * Runs against the real shop (DB, session, OXID models) the way
 * SingleShippingAutoAssignTest and FullDataPersistenceFlowTest already do in
 * this suite; every write happens inside IntegrationTestCase's transaction
 * and is rolled back in tearDown().
 *
 * The test brings its own article and payment method. CI installs a bare CE
 * shop from initial_data.sql: no articles, no oxobject2payment rows, and no
 * PSP module, so neither demo data nor `oe_payments_*` can be relied on.
 * `oxidstandard` (active delivery set) and Germany do ship with initial data.
 */
#[Group('integration')]
final class OxidShopOrderServiceShippingAddressTest extends IntegrationTestCase
{
    private const GERMANY = 'a7c40f631fc920687.20179984';

    private const DELIVERY_SET = 'oxidstandard';

    // Own fixture payment assigned to the delivery set below: any active,
    // basket-checkable payment id proves the seam - the fix does not branch
    // on provider - and a fixture exists on every shop this suite runs on.
    private const PAYMENT_ID = 'e2e_sa_payment';

    private const FIELD_SUFFIXES = [
        'company', 'fname', 'lname', 'street', 'streetnr', 'addinfo', 'city',
        'countryid', 'stateid', 'zip', 'fon', 'fax', 'sal',
    ];

    public function tearDown(): void
    {
        Registry::getSession()->deleteVariable('deladrid');
        unset($_POST['sDeliveryAddressMD5']);
        parent::tearDown();
    }

    public function testCreateOrder_WhenUserShipsToBillingAddress_PersistsBillingIntoShippingColumns(): void
    {
        $user = $this->createFixtureUser('billship');
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        $order = $this->loadOrder($this->createOrder($user));

        foreach (self::FIELD_SUFFIXES as $suffix) {
            self::assertSame(
                $order->getFieldData("oxbill$suffix"),
                $order->getFieldData("oxdel$suffix"),
                "oxdel$suffix should equal oxbill$suffix when the shopper ships to billing"
            );
        }
    }

    public function testCreateOrder_WhenUserSelectedADeliveryAddress_KeepsThatAddress(): void
    {
        $user = $this->createFixtureUser('billkeep');
        $this->putOneArticleInBasketFor($user);
        $address = $this->createFixtureDeliveryAddress($user);
        Registry::getSession()->setVariable('deladrid', $address->getId());
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress() . $address->getEncodedDeliveryAddress();

        $order = $this->loadOrder($this->createOrder($user));

        self::assertSame('Selected-Del-Last', $order->getFieldData('oxdellname'));
        self::assertNotSame($order->getFieldData('oxbilllname'), $order->getFieldData('oxdellname'));
    }

    private function createOrder(User $user): string
    {
        Registry::getSession()->setVariable('sess_challenge', md5(uniqid((string) mt_rand(), true)));

        /** @var ShopOrderServiceInterface $service */
        $service = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);

        $response = $service->createOrder(new CreateOrderRequest(
            sessionId: (string) Registry::getSession()->getId(),
            userId: $user->getId(),
            paymentId: self::PAYMENT_ID,
        ));

        return $response->orderId;
    }

    private function loadOrder(string $orderId): Order
    {
        $order = oxNew(Order::class);
        self::assertTrue($order->load($orderId), 'the order created by createOrder() must be loadable');

        return $order;
    }

    private function putOneArticleInBasketFor(User $user): void
    {
        $this->createFixturePayment();
        $article = $this->createFixtureArticle();

        $basket = oxNew(Basket::class);
        $basket->setBasketUser($user);
        $basket->addToBasket($article->getId(), 1);
        $basket->setPayment(self::PAYMENT_ID);
        $basket->setShipping(self::DELIVERY_SET);
        $basket->calculateBasket();

        Registry::getSession()->setBasket($basket);
    }

    private function createFixtureArticle(): Article
    {
        $article = oxNew(Article::class);
        $article->setId('e2e_sa_art_' . substr(md5(uniqid('', true)), 0, 8));
        $article->oxarticles__oxshopid = new Field(Registry::getConfig()->getShopId(), Field::T_RAW);
        $article->oxarticles__oxactive = new Field(1, Field::T_RAW);
        $article->oxarticles__oxartnum = new Field('E2E-SA-1', Field::T_RAW);
        $article->oxarticles__oxtitle = new Field('Shipping address fixture', Field::T_RAW);
        $article->oxarticles__oxprice = new Field(10.0, Field::T_RAW);
        $article->oxarticles__oxstock = new Field(100, Field::T_RAW);
        $article->oxarticles__oxstockflag = new Field(1, Field::T_RAW);
        $article->save();

        return $article;
    }

    /**
     * An active payment method with no country / group restriction, assigned
     * to the delivery set the basket uses - the two conditions
     * PaymentList::getFilterSelect() demands before Order::validatePayment()
     * accepts a basket.
     */
    private function createFixturePayment(): void
    {
        $payment = oxNew(Payment::class);
        $payment->setId(self::PAYMENT_ID);
        $payment->oxpayments__oxactive = new Field(1, Field::T_RAW);
        $payment->oxpayments__oxdesc = new Field('Shipping address fixture', Field::T_RAW);
        $payment->oxpayments__oxaddsum = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxaddsumtype = new Field('abs', Field::T_RAW);
        $payment->oxpayments__oxfromboni = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxfromamount = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxtoamount = new Field(1000000, Field::T_RAW);
        $payment->oxpayments__oxchecked = new Field(0, Field::T_RAW);
        $payment->oxpayments__oxsort = new Field(0, Field::T_RAW);
        $payment->save();

        $assignment = oxNew(BaseModel::class);
        $assignment->init('oxobject2payment');
        $assignment->oxobject2payment__oxpaymentid = new Field(self::PAYMENT_ID, Field::T_RAW);
        $assignment->oxobject2payment__oxobjectid = new Field(self::DELIVERY_SET, Field::T_RAW);
        $assignment->oxobject2payment__oxtype = new Field('oxdelset', Field::T_RAW);
        $assignment->save();
    }

    private function createFixtureUser(string $suffix): User
    {
        $user = oxNew(User::class);
        $user->setId('e2e_su_' . $suffix . '_' . substr(md5(uniqid('', true)), 0, 8));
        $user->oxuser__oxactive = new Field(1, Field::T_RAW);
        $user->oxuser__oxrights = new Field('user', Field::T_RAW);
        $user->oxuser__oxusername = new Field($user->getId() . '@example.invalid', Field::T_RAW);
        $user->oxuser__oxpassword = new Field('', Field::T_RAW);
        $user->oxuser__oxcompany = new Field('Bill-Company', Field::T_RAW);
        $user->oxuser__oxfname = new Field('Bill-First', Field::T_RAW);
        $user->oxuser__oxlname = new Field('Bill-Last', Field::T_RAW);
        $user->oxuser__oxstreet = new Field('Bill Street', Field::T_RAW);
        $user->oxuser__oxstreetnr = new Field('1', Field::T_RAW);
        $user->oxuser__oxaddinfo = new Field('Bill Addinfo', Field::T_RAW);
        $user->oxuser__oxustid = new Field('', Field::T_RAW);
        $user->oxuser__oxcity = new Field('Bill City', Field::T_RAW);
        $user->oxuser__oxcountryid = new Field(self::GERMANY, Field::T_RAW);
        $user->oxuser__oxstateid = new Field('', Field::T_RAW);
        $user->oxuser__oxzip = new Field('11111', Field::T_RAW);
        $user->oxuser__oxfon = new Field('111', Field::T_RAW);
        $user->oxuser__oxfax = new Field('222', Field::T_RAW);
        $user->oxuser__oxsal = new Field('MR', Field::T_RAW);
        $user->save();

        return $user;
    }

    private function createFixtureDeliveryAddress(User $user): Address
    {
        $address = oxNew(Address::class);
        $address->setId('e2e_sa_' . substr(md5(uniqid('', true)), 0, 8));
        $address->oxaddress__oxuserid = new Field($user->getId(), Field::T_RAW);
        $address->oxaddress__oxaddressuserid = new Field($user->getId(), Field::T_RAW);
        $address->oxaddress__oxcompany = new Field('Selected-Del-Company', Field::T_RAW);
        $address->oxaddress__oxfname = new Field('Selected-Del-First', Field::T_RAW);
        $address->oxaddress__oxlname = new Field('Selected-Del-Last', Field::T_RAW);
        $address->oxaddress__oxstreet = new Field('Selected Del Street', Field::T_RAW);
        $address->oxaddress__oxstreetnr = new Field('2', Field::T_RAW);
        $address->oxaddress__oxaddinfo = new Field('Selected Del Addinfo', Field::T_RAW);
        $address->oxaddress__oxcity = new Field('Selected Del City', Field::T_RAW);
        $address->oxaddress__oxcountryid = new Field(self::GERMANY, Field::T_RAW);
        $address->oxaddress__oxstateid = new Field('', Field::T_RAW);
        $address->oxaddress__oxzip = new Field('22222', Field::T_RAW);
        $address->oxaddress__oxfon = new Field('333', Field::T_RAW);
        $address->oxaddress__oxfax = new Field('444', Field::T_RAW);
        $address->oxaddress__oxsal = new Field('MRS', Field::T_RAW);
        $address->save();

        return $address;
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
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
 * and is rolled back in tearDown(). Fixtures: {@see CheckoutFixture}.
 */
#[Group('integration')]
final class OxidShopOrderServiceShippingAddressTest extends IntegrationTestCase
{
    use CheckoutFixture;

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

        $order = $this->loadOrder($this->createOrderFor($user));

        // Core numbers the order inside finalizeOrder(); the service must not
        // need a module-provided setOrderNumber() for that.
        self::assertGreaterThan(0, (int) $order->getFieldData('oxordernr'));

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

        $order = $this->loadOrder($this->createOrderFor($user));

        self::assertSame('Selected-Del-Last', $order->getFieldData('oxdellname'));
        self::assertNotSame($order->getFieldData('oxbilllname'), $order->getFieldData('oxdellname'));
    }
}

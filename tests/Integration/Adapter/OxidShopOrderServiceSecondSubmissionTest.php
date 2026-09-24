<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * MOL-18 — "Order now" clicked twice: the second submission runs with the
 * SAME `sess_challenge` (core generates it once on the order page), so core's
 * Order::finalizeOrder() answers ORDER_STATE_ORDEREXISTS for it. That is
 * core's own "somebody clicked like mad" blocker, and it must end there.
 *
 * Today OxidShopOrderService treats ORDEREXISTS as success and then saves the
 * never-loaded Order object: a second `oxorder` row with a fresh id, no user,
 * no articles, no payment type, total 0 and a consumed order number. The
 * shopper is then sent to the PSP to pay for that empty order.
 *
 * Runs against the real shop inside IntegrationTestCase's transaction;
 * fixtures from {@see CheckoutFixture}.
 */
#[Group('integration')]
final class OxidShopOrderServiceSecondSubmissionTest extends IntegrationTestCase
{
    use CheckoutFixture;

    public function tearDown(): void
    {
        unset($_POST['sDeliveryAddressMD5']);
        parent::tearDown();
    }

    public function testCreateOrder_SecondCallWithSameSessionChallenge_DoesNotAddAnOrderRow(): void
    {
        $user = $this->createFixtureUser('twice');
        $this->putOneArticleInBasketFor($user);
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();
        $challenge = md5(uniqid((string) mt_rand(), true));

        $rowsBefore = $this->countOrders();
        $phantomsBefore = $this->countPhantomOrders();
        $firstOrderId = $this->createOrderFor($user, $challenge);
        self::assertSame($challenge, $firstOrderId, 'core uses sess_challenge as the order id');

        try {
            $this->createOrderFor($user, $challenge);
        } catch (ShopOrderException) {
            // Refusing the second submission is the correct outcome (Story 2);
            // what this test pins is that no row is written either way.
        }

        self::assertSame(
            $rowsBefore + 1,
            $this->countOrders(),
            'a second submission of the same checkout attempt must not add an oxorder row'
        );
        self::assertSame(
            $phantomsBefore,
            $this->countPhantomOrders(),
            'no new oxorder row without a payment type (the never-loaded Order saved on ORDEREXISTS)'
        );
    }

    private function countOrders(): int
    {
        return (int) DatabaseProvider::getDb()->getOne('SELECT COUNT(*) FROM oxorder');
    }

    /**
     * The phantom's signature: core never assigned a payment because the
     * Order object was never loaded from the basket. Counted before and after
     * because a shop with historic phantoms must not fail a plain "= 0".
     */
    private function countPhantomOrders(): int
    {
        return (int) DatabaseProvider::getDb()->getOne("SELECT COUNT(*) FROM oxorder WHERE OXPAYMENTTYPE = ''");
    }
}

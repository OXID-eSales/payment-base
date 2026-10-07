<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Application\Model\UserBasket;
use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\OxidShopOrderService;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\Guard\IdempotentAttemptGuard;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S3 against the real shop — the headless double click. Without a
 * session core mints a fresh order id per call, so two CheckoutStart calls for
 * one basket would create two orders; the attempt guard on
 * `oe_payments_idempotency` refuses the second one and names the first order.
 */
#[Group('integration')]
final class OxidShopOrderServiceHeadlessDoubleSubmitTest extends IntegrationTestCase
{
    use CheckoutFixture;

    private ?string $idempotencyKey = null;

    public function tearDown(): void
    {
        unset($_POST['sDeliveryAddressMD5'], $_REQUEST['sDeliveryAddressMD5']);
        // The guard writes through the Doctrine connection, which may not be
        // the one IntegrationTestCase rolls back; remove the record ourselves.
        if ($this->idempotencyKey !== null) {
            $this->doctrine()->delete('oe_payments_idempotency', ['OXKEY' => $this->idempotencyKey]);
        }
        parent::tearDown();
    }

    public function testTheSecondHeadlessSubmissionForOneBasketIsRefusedAndAddsNoOrderRow(): void
    {
        $user = $this->createFixtureUser('hds');
        $this->createFixturePayment();
        $article = $this->createFixtureArticle();
        $userBasket = $this->persistUserBasket($user, $article->getId());
        Registry::getSession()->setBasket(oxNew(Basket::class));
        Registry::getSession()->setVariable('sess_challenge', null);

        /** @var ShopOrderServiceInterface $service */
        $service = ContainerFactory::getInstance()->getContainer()->get(ShopOrderServiceInterface::class);
        $request = $this->headlessRequest($user, $userBasket->getId());
        $this->idempotencyKey = IdempotentAttemptGuard::OPERATION . ':' . $user->getId() . ':' . $userBasket->getId();
        $rowsBefore = $this->countOrders();

        $first = $service->createOrder($request);

        try {
            $service->createOrder($request);
            self::fail('the same headless attempt must not create a second order');
        } catch (ShopOrderException $e) {
            self::assertSame(OxidShopOrderService::ERROR_ORDER_EXISTS, $e->getErrorCode());
            self::assertSame($first->orderId, $e->getContext()['order_id'], 'the refusal names the order that exists');
        }

        self::assertSame($rowsBefore + 1, $this->countOrders());

        $record = $this->doctrine()->fetchAssociative(
            'SELECT OXSTATUS, OXORDERID FROM oe_payments_idempotency WHERE OXKEY = :key',
            ['key' => $this->idempotencyKey]
        );
        self::assertIsArray($record, 'the guard recorded the attempt');
        self::assertSame(IdempotentAttemptGuard::STATUS_COMPLETED, $record['OXSTATUS']);
        self::assertSame($first->orderId, $record['OXORDERID']);
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

    private function persistUserBasket(User $user, string $articleId): UserBasket
    {
        $userBasket = oxNew(UserBasket::class);
        $userBasket->setId('e2e_ub_' . substr(md5(uniqid('', true)), 0, 8));
        $userBasket->oxuserbaskets__oxuserid = new Field($user->getId(), Field::T_RAW);
        $userBasket->oxuserbaskets__oxtitle = new Field('graphql-checkout', Field::T_RAW);
        $userBasket->oxuserbaskets__oxpublic = new Field(0, Field::T_RAW);
        $userBasket->save();
        $userBasket->addItemToBasket($articleId, 1);

        return $userBasket;
    }

    private function doctrine(): Connection
    {
        return ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
    }

    private function countOrders(): int
    {
        return (int) DatabaseProvider::getDb()->getOne('SELECT COUNT(*) FROM oxorder');
    }
}

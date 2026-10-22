<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Storefront\Customer\DataType\Customer as StorefrontCustomer;
use OxidEsales\GraphQL\Storefront\Customer\Infrastructure\Customer as CustomerInfrastructure;
use OxidEsales\GraphQL\Storefront\Order\DataType\Order as StorefrontOrder;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\GraphQL\Relation\OrderRelationService;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\CheckoutFixture;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 139c / C3: `Order.paymentContract` against the real shop. The list
 * the field hangs on is the storefront's own (`customer { orders }`), which
 * is what keeps a foreign user's order out of reach; the relation only adds a
 * field to what the storefront already decided to show.
 *
 * Needs graphql-storefront; skipped where it is not installed.
 */
#[Group('integration')]
final class OrderPaymentContractRelationTest extends IntegrationTestCase
{
    use CheckoutFixture;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(StorefrontOrder::class)) {
            self::markTestSkipped('graphql-storefront is not installed');
        }
    }

    public function testPaymentContract_OrderWithACommittedContract_ReturnsStateAndProvider(): void
    {
        $user = $this->createFixtureUser('c3a');
        $orderId = $this->placeOrderFor($user);
        $this->saveCommittedContract($user, $orderId, 'stripe');

        $summary = $this->relation()->paymentContract($this->storefrontOrder($orderId));

        self::assertNotNull($summary);
        self::assertSame('committed', $summary->state());
        self::assertSame('stripe', $summary->provider());
        self::assertNotSame('', $summary->contractId());
    }

    public function testPaymentContract_OrderPlacedWithoutAContract_ReturnsNull(): void
    {
        $user = $this->createFixtureUser('c3b');
        $orderId = $this->placeOrderFor($user);

        self::assertNull($this->relation()->paymentContract($this->storefrontOrder($orderId)));
    }

    public function testCustomerOrders_TwoUsersWithAContractEach_EachSeesOnlyTheirOwnPaymentContract(): void
    {
        $owner = $this->registeredFixtureUser('c3c');
        $stranger = $this->registeredFixtureUser('c3d');
        $ownerOrderId = $this->placeOrderFor($owner);
        $strangerOrderId = $this->placeOrderFor($stranger);
        $ownerContractId = $this->saveCommittedContract($owner, $ownerOrderId, 'paypal');
        $this->saveCommittedContract($stranger, $strangerOrderId, 'mollie');

        $orders = (new CustomerInfrastructure())->getOrders(new StorefrontCustomer($owner));

        self::assertCount(1, $orders, 'the storefront lists the caller\'s orders only');
        $summary = $this->relation()->paymentContract($orders[0]);
        self::assertSame($ownerContractId, $summary?->contractId());
        self::assertSame('paypal', $summary->provider());
    }

    public function tearDown(): void
    {
        unset($_POST['sDeliveryAddressMD5']);
        parent::tearDown();
    }

    private function placeOrderFor(User $user): string
    {
        $this->putOneArticleInBasketFor($user);
        // core's Order::validateDeliveryAddress() compares this with the user's current address
        $_POST['sDeliveryAddressMD5'] = $user->getEncodedDeliveryAddress();

        return $this->createOrderFor($user);
    }

    /**
     * core's User::getOrders() lists orders of registered accounts only
     * (`oxregister` set), which is what the storefront's order list relies on.
     */
    private function registeredFixtureUser(string $suffix): User
    {
        $user = $this->createFixtureUser($suffix);
        $user->oxuser__oxregister = new Field('2020-01-01 00:00:00', Field::T_RAW);
        $user->save();

        return $user;
    }

    private function storefrontOrder(string $orderId): StorefrontOrder
    {
        return new StorefrontOrder($this->loadOrder($orderId));
    }

    private function relation(): OrderRelationService
    {
        /** @var OrderRelationService $relation */
        $relation = ContainerFactory::getInstance()->getContainer()->get(OrderRelationService::class);

        return $relation;
    }

    private function saveCommittedContract(User $user, string $orderId, string $provider): string
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, (string) $user->getId(), $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider($provider, 'prov_1');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);

        /** @var ContractRepositoryInterface $repository */
        $repository = ContainerFactory::getInstance()->getContainer()->get(ContractRepositoryInterface::class);
        $repository->save($contract);

        return (string) $contract->getId();
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\OpenAttemptFinderInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S3 — "the open attempt for this user's basket", read from the
 * `basket_id` the handler stamps into the contract metadata. Only attempts
 * that are still open count: an authorized, committed or terminal contract
 * is money or history and must never be offered for retirement.
 */
#[Group('integration')]
final class DoctrineContractRepositoryOpenByBasketTest extends TestCase
{
    private ContractRepositoryInterface $contracts;

    private OpenAttemptFinderInterface $finder;

    /** @var list<string> */
    private array $orderIds = [];

    public function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $this->contracts = $container->get(ContractRepositoryInterface::class);
        $this->finder = $container->get(OpenAttemptFinderInterface::class);
    }

    public function tearDown(): void
    {
        $connection = ContainerFactory::getInstance()->getContainer()
            ->get(\OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface::class)
            ->get();
        foreach ($this->orderIds as $orderId) {
            $connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXORDERID = :id', ['id' => $orderId]);
        }

    }

    public function testFindsTheNewestOpenContractStampedWithTheBasket(): void
    {
        $user = 'it_user_' . uniqid();
        $older = $this->contract($user, 'ub-1');
        $older->transitionToNotFinished($this->orderId('a'));
        $this->contracts->save($older);
        // OXCREATED is DATETIME at second precision and set by the database
        // on insert; without a gap "newest" would be a coin toss.
        sleep(1);
        $newer = $this->contract($user, 'ub-1');
        $newer->transitionToNotFinished($this->orderId('b'));
        $newer->transitionToPending();
        $this->contracts->save($newer);
        $this->contracts->save($this->contract($user, 'ub-other'));

        $found = $this->finder->findOpenByUserAndBasketId($user, 'ub-1');

        self::assertNotNull($found);
        self::assertSame($newer->getId(), $found->getId());
    }

    public function testSettledOrTerminalContractsAreNotOpen(): void
    {
        $user = 'it_user_' . uniqid();
        $authorized = $this->contract($user, 'ub-1');
        $authorized->transitionToNotFinished($this->orderId('c'));
        $authorized->transitionToPending();
        $authorized->authorize();
        $this->contracts->save($authorized);
        $cancelled = $this->contract($user, 'ub-1');
        $cancelled->transitionToNotFinished($this->orderId('d'));
        $cancelled->cancel('shopper left');
        $this->contracts->save($cancelled);

        self::assertNull($this->finder->findOpenByUserAndBasketId($user, 'ub-1'));
        self::assertNull($this->finder->findOpenByUserAndBasketId($user, 'never-seen'));
        self::assertNull($this->finder->findOpenByUserAndBasketId('nobody', 'ub-1'));
    }

    /**
     * OPC-175: one order, one contract (unique OXORDERID). The contracts are
     * written through the repository's own connection, which the test's
     * transaction does not cover, so each run needs its own order ids and
     * removes its rows itself.
     */
    private function orderId(string $name): string
    {
        $orderId = 'it_order_' . $name . '_' . uniqid();
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function contract(string $userId, string $basketId): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, $userId, $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->setMetadata('basket_id', $basketId);

        return $contract;
    }
}

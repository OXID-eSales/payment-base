<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2b — one order, one active contract.
 *
 * When the customer changes the payment method, the order is kept and a new
 * contract of the new provider gets the same OXORDERID; the old one is
 * cancelled. findByOrderId() (28 callers: admin tab, capture, refund, mails)
 * must then answer the contract that has not ended. Before, the query had no
 * ORDER BY and MySQL picked any row.
 *
 * A plain TestCase with prefixed rows: DoctrineContractRepositoryTest empties
 * the whole contract table in its cleanup.
 */
#[Group('database')]
final class FindByOrderIdActiveContractTest extends TestCase
{
    private const PREFIX = 'pbtest_s2b_';
    private const ORDER_ID = 'pbtest_s2b_order';

    private Connection $connection;
    private ContractRepositoryInterface $contracts;
    /** @var array<string, string> contract id => OXCREATED to stamp after the save */
    private array $createdAt = [];

    public function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        $this->contracts = $container->get(ContractRepositoryInterface::class);
        $this->cleanupTestData();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testFindByOrderId_PrefersTheContractThatHasNotEnded(): void
    {
        $this->save($this->pending('new', '2026-10-29 10:00:00'));
        $this->save($this->cancelled('old', '2026-10-29 09:00:00'));
        // Created after the active one: newer is not enough to win.
        $this->save($this->cancelled('late', '2026-10-29 11:00:00'));

        self::assertSame(self::PREFIX . 'new', $this->contracts->findByOrderId(self::ORDER_ID)?->getId());
    }

    public function testFindByOrderId_WhenAllHaveEnded_IsTheNewest(): void
    {
        $this->save($this->cancelled('first', '2026-10-29 09:00:00'));
        $this->save($this->cancelled('second', '2026-10-29 10:00:00'));

        self::assertSame(self::PREFIX . 'second', $this->contracts->findByOrderId(self::ORDER_ID)?->getId());
    }

    public function testFindByOrderId_AFulfilledContractIsActive(): void
    {
        $fulfilled = $this->pending('paid', '2026-10-29 09:00:00');
        $fulfilled->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $fulfilled->commitToOrder(self::ORDER_ID);
        $fulfilled->fulfill();
        $this->save($fulfilled);
        $this->save($this->cancelled('superseded', '2026-10-29 10:00:00'));

        self::assertSame(self::PREFIX . 'paid', $this->contracts->findByOrderId(self::ORDER_ID)?->getId());
    }

    private function save(PaymentContract $contract): void
    {
        $this->contracts->save($contract);
        $this->connection->executeStatement(
            'UPDATE oe_payments_contract SET OXCREATED = :at WHERE OXID = :id',
            ['at' => $this->createdAt[$contract->getId()], 'id' => $contract->getId()]
        );
    }

    private function pending(string $suffix, string $createdAt): PaymentContract
    {
        $contract = new PaymentContract(1, 'pbtest_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 35.29, 'totalVat' => 6.71, 'currency' => 'EUR',
        ]), self::PREFIX . $suffix);
        $this->createdAt[self::PREFIX . $suffix] = $createdAt;
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished(self::ORDER_ID);
        $contract->transitionToPending();

        return $contract;
    }

    private function cancelled(string $suffix, string $createdAt): PaymentContract
    {
        $contract = $this->pending($suffix, $createdAt);
        $contract->cancel('payment_method_changed');

        return $contract;
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_contract WHERE OXID LIKE :p',
            ['p' => self::PREFIX . '%']
        );
    }
}

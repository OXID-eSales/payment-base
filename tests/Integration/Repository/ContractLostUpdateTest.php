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
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use PHPUnit\Framework\Attributes\Group;

/**
 * MOL-17 — the shopper's return leg and the PSP's `paid` webhook both load the contract, mutate their
 * own copy and save. Before this sprint the second save silently overwrote the first: a `fulfilled`
 * contract read `committed` again, and the admin Refund form (which needs `fulfilled`) never appeared.
 */
#[Group('database')]
final class ContractLostUpdateTest extends IntegrationTestCase
{
    private const ID = 'mol17_lost_update';

    private DoctrineContractRepository $repository;
    private Connection $connection;

    public function setUp(): void
    {
        parent::setUp();
        $this->connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->repository = new DoctrineContractRepository($this->connection);
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = ?', [self::ID]);
    }

    public function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = ?', [self::ID]);
        parent::tearDown();
    }

    public function testASaveFromAStaleCopyDoesNotOverwriteANewerState(): void
    {
        $this->seedPendingContract();
        $returnLeg = $this->repository->findById(self::ID);   // loaded first, saved last
        $webhook = $this->repository->findById(self::ID);

        // The webhook wins the race: it commits, fulfils and saves.
        $webhook->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $webhook->commitToOrder('order-1');
        $this->repository->save($webhook);
        $webhook->fulfill();
        $this->repository->save($webhook);

        // The return leg, unaware, commits its stale copy.
        $returnLeg->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $returnLeg->commitToOrder('order-1');
        try {
            $this->repository->save($returnLeg);
        } catch (StaleContractException) {
            // Refusing the stale write is the correct outcome (Story 2).
        }

        $stored = $this->repository->findById(self::ID);
        $this->assertNotNull($stored);
        $this->assertSame('fulfilled', $stored->getStateValue(), 'the newer state must survive the stale save');
        $this->assertNotNull($stored->getFulfilledAt());
    }

    public function testTheStaleWriterIsToldSo(): void
    {
        $this->seedPendingContract();
        $stale = $this->repository->findById(self::ID);
        $fresh = $this->repository->findById(self::ID);

        $fresh->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->repository->save($fresh);

        $this->expectException(StaleContractException::class);
        $stale->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->repository->save($stale);
    }

    public function testAFreshCopySavesAgainAfterReloading(): void
    {
        $this->seedPendingContract();
        $first = $this->repository->findById(self::ID);
        $this->repository->save($first);
        $this->repository->save($first);   // the in-memory version follows the row

        $reloaded = $this->repository->findById(self::ID);
        $reloaded->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $this->repository->save($reloaded);

        $this->assertSame('ready_to_commit', $this->repository->findById(self::ID)?->getStateValue());
    }

    private function seedPendingContract(): void
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 116.5, 'totalNet' => 97.9, 'totalVat' => 18.6, 'currency' => 'EUR',
        ]), self::ID);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $this->repository->save($contract);
    }
}

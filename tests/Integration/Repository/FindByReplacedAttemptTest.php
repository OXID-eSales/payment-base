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
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2g — a late provider event of a replaced attempt finds the
 * contract through the attempt history in OXMETADATA.
 */
#[Group('database')]
final class FindByReplacedAttemptTest extends TestCase
{
    private const ID = 'pbtest_s2g_contract';

    private Connection $connection;

    public function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
        $this->cleanup();
    }

    public function tearDown(): void
    {
        $this->cleanup();
    }

    public function testFindByReplacedAttempt_FindsTheContractThatReplacedIt(): void
    {
        $contract = new PaymentContract(1, 'pbtest_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 42.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), self::ID);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('pbtest_s2g_order');
        $contract->transitionToPending();
        $contract->setProvider('mollie', 'tr_pbtest_s2g');
        $contract->reopenForNewAttempt(AttemptClosure::Open, $contract->getBasketSnapshot(), [ContractCondition::TYPE_PAYMENT_AUTHORIZED]);
        $container = ContainerFactory::getInstance()->getContainer();
        $container->get(ContractRepositoryInterface::class)->save($contract);
        $finder = $container->get(ReplacedAttemptFinderInterface::class);

        self::assertSame(self::ID, $finder->findByReplacedAttempt('tr_pbtest_s2g')?->getId());
        self::assertNull($finder->findByReplacedAttempt('tr_pbtest_unknown'));
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => self::ID]);
    }
}

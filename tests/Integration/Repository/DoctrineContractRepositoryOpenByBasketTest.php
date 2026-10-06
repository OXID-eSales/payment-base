<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 15 / S3 — "the open attempt for this user's basket", read from the
 * `basket_id` the handler stamps into the contract metadata. Only attempts
 * that are still open count: an authorized, committed or terminal contract
 * is money or history and must never be offered for retirement.
 */
#[Group('integration')]
final class DoctrineContractRepositoryOpenByBasketTest extends IntegrationTestCase
{
    private ContractRepositoryInterface $contracts;

    public function setUp(): void
    {
        parent::setUp();
        $this->contracts = ContainerFactory::getInstance()->getContainer()->get(ContractRepositoryInterface::class);
    }

    public function testFindsTheNewestOpenContractStampedWithTheBasket(): void
    {
        $user = 'it_user_' . uniqid();
        $older = $this->contract($user, 'ub-1');
        $older->transitionToNotFinished('order-a');
        $this->contracts->save($older);
        // OXCREATED is DATETIME at second precision and set by the database
        // on insert; without a gap "newest" would be a coin toss.
        sleep(1);
        $newer = $this->contract($user, 'ub-1');
        $newer->transitionToNotFinished('order-b');
        $newer->transitionToPending();
        $this->contracts->save($newer);
        $this->contracts->save($this->contract($user, 'ub-other'));

        $found = $this->contracts->findOpenByUserAndBasketId($user, 'ub-1');

        self::assertNotNull($found);
        self::assertSame($newer->getId(), $found->getId());
    }

    public function testSettledOrTerminalContractsAreNotOpen(): void
    {
        $user = 'it_user_' . uniqid();
        $authorized = $this->contract($user, 'ub-1');
        $authorized->transitionToNotFinished('order-c');
        $authorized->transitionToPending();
        $authorized->authorize();
        $this->contracts->save($authorized);
        $cancelled = $this->contract($user, 'ub-1');
        $cancelled->transitionToNotFinished('order-d');
        $cancelled->cancel('shopper left');
        $this->contracts->save($cancelled);

        self::assertNull($this->contracts->findOpenByUserAndBasketId($user, 'ub-1'));
        self::assertNull($this->contracts->findOpenByUserAndBasketId($user, 'never-seen'));
        self::assertNull($this->contracts->findOpenByUserAndBasketId('nobody', 'ub-1'));
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

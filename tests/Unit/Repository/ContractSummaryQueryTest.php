<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ContractSummaryQuery;
use OxidEsales\PaymentBase\Repository\ContractSummaryQueryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ContractSummaryQueryTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testImplementsTheContract(): void
    {
        self::assertInstanceOf(ContractSummaryQueryInterface::class, new ContractSummaryQuery($this->contracts));
    }

    public function testFindByOrderId_OrderWithACommittedContract_ReturnsItsSummary(): void
    {
        $contract = $this->committedContract('contract-1', 'order-1');
        $this->contracts->method('findByOrderId')->with('order-1')->willReturn($contract);

        $summary = (new ContractSummaryQuery($this->contracts))->findByOrderId('order-1');

        self::assertNotNull($summary);
        self::assertSame('contract-1', $summary->contractId);
        self::assertSame('committed', $summary->state);
        self::assertSame('stripe', $summary->provider);
        self::assertEquals($contract->getUpdatedAt(), $summary->updatedAt);
    }

    /**
     * Found against the live schema: GraphQLite's DateTime scalar accepts
     * DateTimeImmutable only, and contracts carry a mutable DateTime.
     */
    public function testFindByOrderId_ContractWithAMutableDate_ReturnsAnImmutableUpdatedAt(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->committedContract('contract-3', 'order-3'));

        $summary = (new ContractSummaryQuery($this->contracts))->findByOrderId('order-3');

        self::assertInstanceOf(DateTimeImmutable::class, $summary?->updatedAt);
    }

    public function testFindByOrderId_OrderWithoutAContract_ReturnsNull(): void
    {
        $this->contracts->method('findByOrderId')->with('order-twig')->willReturn(null);

        self::assertNull((new ContractSummaryQuery($this->contracts))->findByOrderId('order-twig'));
    }

    public function testFindByOrderId_ContractWithoutProvider_ReturnsAnEmptyProviderNotNull(): void
    {
        $contract = $this->committedContract('contract-2', 'order-2', provider: null);
        $this->contracts->method('findByOrderId')->willReturn($contract);

        $summary = (new ContractSummaryQuery($this->contracts))->findByOrderId('order-2');

        self::assertSame('', $summary?->provider);
    }

    private function committedContract(string $id, string $orderId, ?string $provider = 'stripe'): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        if ($provider !== null) {
            $contract->setProvider($provider, 'cs_1');
        }
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);

        return $contract;
    }
}

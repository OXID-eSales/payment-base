<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractOrderOwnership;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2b — a contract may change its order only while it is the
 * order's active contract. A contract that was replaced (the customer changed
 * the payment method and the order now belongs to the new contract) must not
 * cancel or fail that order, whatever its PSP reports later.
 */
final class ContractOrderOwnershipTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testOwnsItsOrder_WhenItIsTheActiveContract(): void
    {
        $contract = $this->contract('ctr-1');
        $this->contracts->method('findByOrderId')->with('order-1')->willReturn($contract);

        self::assertTrue($this->ownership()->ownsItsOrder($contract));
    }

    public function testOwnsItsOrder_IsFalse_WhenAnotherContractIsActive(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract('ctr-2'));

        self::assertFalse($this->ownership()->ownsItsOrder($this->contract('ctr-1')));
    }

    /** Nothing to compare against: keep the behaviour from before. */
    public function testOwnsItsOrder_WhenTheLookupFindsNothing(): void
    {
        $this->contracts->method('findByOrderId')->willReturn(null);

        self::assertTrue($this->ownership()->ownsItsOrder($this->contract('ctr-1')));
    }

    public function testOwnsItsOrder_IsFalse_WithoutAnOrder(): void
    {
        $this->contracts->expects($this->never())->method('findByOrderId');
        $draft = new PaymentContract(1, 'user-1', $this->basket(), 'ctr-1');

        self::assertFalse($this->ownership()->ownsItsOrder($draft));
    }

    private function ownership(): ContractOrderOwnership
    {
        return new ContractOrderOwnership($this->contracts);
    }

    private function contract(string $id): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', $this->basket(), $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();

        return $contract;
    }

    private function basket(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 35.29, 'totalVat' => 6.71, 'currency' => 'EUR',
        ]);
    }
}

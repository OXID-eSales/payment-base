<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use PHPUnit\Framework\TestCase;

final class PaymentContractDetailsReaderTest extends TestCase
{
    private InMemoryContracts $contracts;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
    }

    public function testFindByOrderId_NoContract_ReturnsNull(): void
    {
        self::assertNull($this->reader([])->findByOrderId('order-unknown'));
    }

    public function testFindByOrderId_FulfilledContract_ReturnsAmountsStateAndProvider(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'mollie', 100.0, 100.0, 25.0));

        $details = $this->reader([])->findByOrderId('order-1');

        self::assertNotNull($details);
        self::assertSame((string) $this->contracts->findByOrderId('order-1')?->getId(), $details->contractId);
        self::assertSame('mollie', $details->provider);
        self::assertSame('fulfilled', $details->state);
        self::assertSame(100.0, $details->amountAuthorised);
        self::assertSame(100.0, $details->amountCaptured);
        self::assertSame(25.0, $details->amountRefunded);
        self::assertSame('EUR', $details->currency);
    }

    public function testFindByOrderId_UncapturedContract_ReportsZeroCapturedAndRefunded(): void
    {
        $this->contracts->save(ContractFactory::authorized('order-2'));

        $details = $this->reader([])->findByOrderId('order-2');

        self::assertNotNull($details);
        self::assertSame(0.0, $details->amountCaptured);
        self::assertSame(0.0, $details->amountRefunded);
    }

    public function testFindByOrderId_Transactions_AreMappedWithoutAnythingButIdsAndAmounts(): void
    {
        $contract = ContractFactory::fulfilled('order-3');
        $this->contracts->save($contract);
        $capture = new Transaction('t-1', 1, 'order-3', $contract->getId(), 'paypal', 'capture', 'completed', 100.0, 'EUR');
        $capture->setTransactionId('CAP-123');

        $details = $this->reader([$capture])->findByOrderId('order-3');

        self::assertNotNull($details);
        self::assertCount(1, $details->transactions);
        $row = $details->transactions[0];
        self::assertSame('t-1', $row->id);
        self::assertSame('capture', $row->type);
        self::assertSame('completed', $row->status);
        self::assertSame(100.0, $row->amount);
        self::assertSame('CAP-123', $row->providerReference);
        self::assertSame('EUR', $row->currency);
    }

    /**
     * @param list<Transaction> $transactions
     */
    private function reader(array $transactions): PaymentContractDetailsReader
    {
        $repository = $this->createStub(TransactionRepositoryInterface::class);
        $repository->method('findByContractId')->willReturn($transactions);

        return new PaymentContractDetailsReader($this->contracts, $repository);
    }
}

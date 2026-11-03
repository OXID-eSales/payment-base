<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Refund;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedger;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * VR-21 F3 — what the ledger refuses before it touches the database. The booking
 * itself runs against the real tables: tests/Integration/Refund/ProviderRefundLedgerTest.
 */
final class ProviderRefundLedgerTest extends TestCase
{
    public function testARefundWithoutItsProviderIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund id');

        $this->ledgerThatMustNotTouchTheDatabase()->book('contract-1', '', 10.0, 'EUR', 'webhook');
    }

    public function testANonPositiveAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');

        $this->ledgerThatMustNotTouchTheDatabase()->book('contract-1', 're_1', 0.0, 'EUR', 'admin');
    }

    private function ledgerThatMustNotTouchTheDatabase(): ProviderRefundLedger
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');

        return new ProviderRefundLedger(
            $connection,
            $this->createMock(ContractRepositoryInterface::class),
            $this->createMock(TransactionRepositoryInterface::class),
        );
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Refund;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Refund\ProviderChargebackLedger;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * VR-21 F3 — what the chargeback ledger refuses before it touches the database; the booking itself:
 * tests/Integration/Refund/ProviderChargebackLedgerTest.
 */
final class ProviderChargebackLedgerTest extends TestCase
{
    public function testANegativeChargebackTotalIsRefused(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');
        $ledger = new ProviderChargebackLedger(
            $connection,
            $this->createMock(ContractRepositoryInterface::class),
            $this->createMock(TransactionRepositoryInterface::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('negative');

        $ledger->book('contract-1', null, -1.0, 'EUR', 'webhook');
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedger;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the ledger refuses before it touches the database; the booking runs against the real tables
 * (tests/Integration/Payment/ProviderCaptureLedgerTest).
 */
final class ProviderCaptureLedgerTest extends TestCase
{
    public function testACaptureWithoutItsProviderIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capture id');

        $this->ledgerThatMustNotTouchTheDatabase()->book('contract-1', '', 10.0, 'EUR', 'admin');
    }

    public function testANonPositiveAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');

        $this->ledgerThatMustNotTouchTheDatabase()->book('contract-1', 'cap_1', 0.0, 'EUR', 'admin');
    }

    private function ledgerThatMustNotTouchTheDatabase(): ProviderCaptureLedger
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');

        return new ProviderCaptureLedger(
            $connection,
            $this->createMock(ContractRepositoryInterface::class),
            $this->createMock(TransactionRepositoryInterface::class),
            new NullLogger(),
        );
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Checkout;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S0 — the retire every provider now runs before a new early order
 * (EarlyOrderCreationHandler; Stripe's OPC path since S1), through the
 * container's cleaner and against the real tables.
 *
 * An open attempt gives its voucher back and its order is stornoed, so core's
 * validateVouchers() accepts the voucher on the next order. A committed
 * attempt is money: it keeps its order and its voucher.
 *
 * Provider `mollie` on purpose: Stripe's decorator would ask the Stripe API.
 *
 * A plain TestCase, not IntegrationTestCase: the cleaner writes through more
 * than one connection, and the wrapping transaction made them wait for each
 * other (lock wait timeout). Rows are prefixed and removed explicitly.
 */
#[Group('database')]
final class RetireReleasesVoucherTest extends TestCase
{
    private const PREFIX = 'pbtest_opc175_';

    private Connection $connection;
    private ContractRepositoryInterface $contracts;
    private PreviousCheckoutAttemptCleanerInterface $cleaner;

    public function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        $this->contracts = $container->get(ContractRepositoryInterface::class);
        $this->cleaner = $container->get(PreviousCheckoutAttemptCleanerInterface::class);

        $this->cleanupTestData();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
    }

    public function testClean_OpenAttempt_StornosTheOrderReleasesTheVoucherAndCancelsTheContract(): void
    {
        $this->seedOrder('ord1');
        $this->seedVoucher('vou1', self::PREFIX . 'ord1');
        $contract = $this->contract('ctr1', self::PREFIX . 'ord1');
        $this->contracts->save($contract);

        self::assertTrue($this->cleaner->clean(self::PREFIX . 'ctr1'));

        self::assertSame('CANCELLED', $this->orderField('ord1', 'OXTRANSSTATUS'));
        self::assertSame('1', $this->orderField('ord1', 'OXSTORNO'));
        self::assertSame('', $this->voucherField('vou1', 'OXORDERID'));
        self::assertNull($this->connection->fetchOne(
            'SELECT OXDATEUSED FROM oxvouchers WHERE OXID = :id',
            ['id' => self::PREFIX . 'vou1']
        ));
        self::assertTrue($this->contracts->findById(self::PREFIX . 'ctr1')?->getState()->isCancelled());
    }

    public function testClean_CommittedAttempt_KeepsTheOrderAndTheVoucher(): void
    {
        $this->seedOrder('ord2');
        $this->seedVoucher('vou2', self::PREFIX . 'ord2');
        $contract = $this->contract('ctr2', self::PREFIX . 'ord2');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder(self::PREFIX . 'ord2');
        $this->contracts->save($contract);

        self::assertFalse($this->cleaner->clean(self::PREFIX . 'ctr2'));

        self::assertSame('NOT_FINISHED', $this->orderField('ord2', 'OXTRANSSTATUS'));
        self::assertSame(self::PREFIX . 'ord2', $this->voucherField('vou2', 'OXORDERID'));
    }

    private function contract(string $suffix, string $orderId): PaymentContract
    {
        $contract = new PaymentContract(1, 'pbtest_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 35.29, 'totalVat' => 6.71, 'currency' => 'EUR',
        ]), self::PREFIX . $suffix);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider('mollie', 'tr_' . $suffix);

        return $contract;
    }

    private function orderField(string $suffix, string $column): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT {$column} FROM oxorder WHERE OXID = :id",
            ['id' => self::PREFIX . $suffix]
        );
    }

    private function voucherField(string $suffix, string $column): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT {$column} FROM oxvouchers WHERE OXID = :id",
            ['id' => self::PREFIX . $suffix]
        );
    }

    private function seedOrder(string $suffix): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oxorder (OXID, OXSHOPID, OXORDERDATE, OXTRANSSTATUS, OXSTORNO, OXCARDTEXT, OXREMARK)
             VALUES (:id, 1, NOW(), \'NOT_FINISHED\', 0, \'\', \'\')',
            ['id' => self::PREFIX . $suffix]
        );
    }

    private function seedVoucher(string $suffix, string $orderId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oxvouchers (OXID, OXORDERID, OXUSERID, OXDISCOUNT, OXDATEUSED, OXRESERVED)
             VALUES (:id, :orderId, \'pbtest_user\', 5, NOW(), 1)',
            ['id' => self::PREFIX . $suffix, 'orderId' => $orderId]
        );
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement('DELETE FROM oxorder WHERE OXID LIKE :p', ['p' => self::PREFIX . '%']);
        $this->connection->executeStatement('DELETE FROM oxvouchers WHERE OXID LIKE :p', ['p' => self::PREFIX . '%']);
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_contract WHERE OXID LIKE :p',
            ['p' => self::PREFIX . '%']
        );
    }
}

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
use OxidEsales\PaymentBase\Repository\DoctrineNotFinishedOrderRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * The write half of the abandoned-checkout collector, against a real database.
 *
 * The unit tests pin the decision logic; only this one proves the SQL — the age
 * arithmetic, the guarded UPDATE, and the voucher release — actually behaves on
 * MySQL. Every row it touches is prefixed `pbtest_` and removed again.
 */
#[Group('database')]
class DoctrineNotFinishedOrderRepositoryTest extends IntegrationTestCase
{
    private const PREFIX = 'pbtest_nfo_';

    private DoctrineNotFinishedOrderRepository $repository;
    private Connection $connection;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        $this->repository = new DoctrineNotFinishedOrderRepository($this->connection);

        $this->cleanupTestData();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    public function testFindsOnlyOrdersOlderThanThePeriod(): void
    {
        $this->seedOrder('old', daysOld: 60);
        $this->seedOrder('fresh', daysOld: 1);

        $found = $this->repository->findStaleNotFinishedOrderIds(30);

        $this->assertContains(self::PREFIX . 'old', $found);
        $this->assertNotContains(self::PREFIX . 'fresh', $found);
    }

    public function testIgnoresOrdersThatAreNotUnfinished(): void
    {
        $this->seedOrder('paid', daysOld: 60, status: 'OK');

        $this->assertNotContains(
            self::PREFIX . 'paid',
            $this->repository->findStaleNotFinishedOrderIds(30)
        );
    }

    /**
     * A previous run already collected these. Handing them back every night
     * would make the reported counts meaningless.
     */
    public function testIgnoresOrdersThatAreAlreadyStornoed(): void
    {
        $this->seedOrder('gone', daysOld: 60, storno: 1);

        $this->assertNotContains(
            self::PREFIX . 'gone',
            $this->repository->findStaleNotFinishedOrderIds(30)
        );
    }

    public function testRestrictsToTheGivenShop(): void
    {
        $this->seedOrder('shop1', daysOld: 60, shopId: 1);
        $this->seedOrder('shop2', daysOld: 60, shopId: 2);

        $found = $this->repository->findStaleNotFinishedOrderIds(30, 2);

        $this->assertContains(self::PREFIX . 'shop2', $found);
        $this->assertNotContains(self::PREFIX . 'shop1', $found);
    }

    public function testHonoursTheLimit(): void
    {
        $this->seedOrder('a', daysOld: 60);
        $this->seedOrder('b', daysOld: 59);

        $this->assertCount(1, $this->repository->findStaleNotFinishedOrderIds(30, null, 1));
    }

    public function testCancelKeepsTheRowAndMovesItOffNotFinished(): void
    {
        $this->seedOrder('cancel-me', daysOld: 60);

        $this->assertTrue($this->repository->cancelOrder(self::PREFIX . 'cancel-me'));

        $row = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS, OXSTORNO FROM oxorder WHERE OXID = ?',
            [self::PREFIX . 'cancel-me']
        );

        $this->assertNotFalse($row, 'The order row must be preserved, not deleted.');
        $this->assertSame('CANCELLED', $row['OXTRANSSTATUS']);
        $this->assertSame(1, (int) $row['OXSTORNO']);
    }

    /**
     * The status guard is what makes a concurrent sweep safe: if the customer
     * finished paying between the query and the write, the order is not ours
     * to cancel any more.
     */
    public function testCancelRefusesAnOrderThatIsNoLongerUnfinished(): void
    {
        $this->seedOrder('finished', daysOld: 60, status: 'OK');

        $this->assertFalse($this->repository->cancelOrder(self::PREFIX . 'finished'));

        $status = $this->connection->fetchOne(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = ?',
            [self::PREFIX . 'finished']
        );

        $this->assertSame('OK', $status, 'A finished order must survive the sweep untouched.');
    }

    public function testCancellingTwiceReportsOnlyTheFirstCall(): void
    {
        $this->seedOrder('once', daysOld: 60);

        $this->assertTrue($this->repository->cancelOrder(self::PREFIX . 'once'));
        $this->assertFalse(
            $this->repository->cancelOrder(self::PREFIX . 'once'),
            'A second call must not be counted as a second cancellation.'
        );
    }

    public function testCancelIsSilentAboutAnUnknownOrder(): void
    {
        $this->assertFalse($this->repository->cancelOrder(self::PREFIX . 'does-not-exist'));
    }

    public function testReleasingVouchersClearsTheReservation(): void
    {
        $orderId = self::PREFIX . 'with-voucher';
        $this->seedOrder('with-voucher', daysOld: 60);
        $this->seedVoucher('v1', $orderId);

        $this->assertSame(1, $this->repository->releaseVouchers($orderId));

        $row = $this->connection->fetchAssociative(
            'SELECT OXORDERID, OXUSERID, OXDISCOUNT, OXDATEUSED, OXRESERVED FROM oxvouchers WHERE OXID = ?',
            [self::PREFIX . 'v1']
        );

        $this->assertNotFalse($row);
        $this->assertSame('', $row['OXORDERID']);
        $this->assertSame('', $row['OXUSERID']);
        $this->assertSame(0.0, (float) $row['OXDISCOUNT']);
        $this->assertNull($row['OXDATEUSED']);
        $this->assertSame(0, (int) $row['OXRESERVED']);
    }

    public function testReleasingVouchersLeavesOtherOrdersAlone(): void
    {
        $this->seedVoucher('mine', self::PREFIX . 'order-a');
        $this->seedVoucher('theirs', self::PREFIX . 'order-b');

        $this->assertSame(1, $this->repository->releaseVouchers(self::PREFIX . 'order-a'));

        $this->assertSame(
            self::PREFIX . 'order-b',
            $this->connection->fetchOne('SELECT OXORDERID FROM oxvouchers WHERE OXID = ?', [self::PREFIX . 'theirs'])
        );
    }

    private function seedOrder(
        string $suffix,
        int $daysOld,
        string $status = 'NOT_FINISHED',
        int $storno = 0,
        int $shopId = 1
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO oxorder (OXID, OXSHOPID, OXORDERDATE, OXTRANSSTATUS, OXSTORNO, OXCARDTEXT, OXREMARK)
             VALUES (:id, :shopId, DATE_SUB(NOW(), INTERVAL :days DAY), :status, :storno, \'\', \'\')',
            [
                'id' => self::PREFIX . $suffix,
                'shopId' => $shopId,
                'days' => $daysOld,
                'status' => $status,
                'storno' => $storno,
            ]
        );
    }

    private function seedVoucher(string $suffix, string $orderId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oxvouchers (OXID, OXORDERID, OXUSERID, OXDISCOUNT, OXDATEUSED, OXRESERVED)
             VALUES (:id, :orderId, \'someuser\', 5, NOW(), 1)',
            ['id' => self::PREFIX . $suffix, 'orderId' => $orderId]
        );
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement('DELETE FROM oxorder WHERE OXID LIKE :p', ['p' => self::PREFIX . '%']);
        $this->connection->executeStatement('DELETE FROM oxvouchers WHERE OXID LIKE :p', ['p' => self::PREFIX . '%']);
    }
}

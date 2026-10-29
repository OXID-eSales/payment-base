<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use Doctrine\Migrations\Query\Query;
use OxidEsales\PaymentBase\Migrations\Version20261029100000;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The migration with the duplicate lookup scripted: a migrated database
 * cannot hold an order with two contracts any more.
 */
final class ScriptedDuplicatesVersion20261029100000 extends Version20261029100000
{
    /** @var array<string, string> */
    public array $duplicates = [];

    protected function ordersWithMoreThanOneContract(): array
    {
        return $this->duplicates;
    }
}

/**
 * OPC-175 / S2b — one order, one contract: OXORDERID is unique.
 * Runs against the migrated shop database.
 */
final class OneOrderOneContractMigrationTest extends MigrationTestBase
{
    public function testTheUniqueIndexReplacesTheOldIndex(): void
    {
        $table = $this->schema->getTable('oe_payments_contract');

        self::assertTrue($table->hasIndex('UNQ_ORDER'));
        self::assertTrue($table->getIndex('UNQ_ORDER')->isUnique());
        self::assertSame(['OXORDERID'], $table->getIndex('UNQ_ORDER')->getColumns());
        self::assertFalse($table->hasIndex('IDX_ORDER'));
    }

    public function testASecondContractForTheSameOrderIsRefusedByTheDatabase(): void
    {
        $orderId = 'pbtest_s2b_' . uniqid();
        $insert = 'INSERT INTO oe_payments_contract (OXID, OXSHOPID, OXUSERID, OXORDERID, OXSTATE, OXBASKETDATA)
                   VALUES (:id, 1, :user, :orderId, :state, :basket)';
        $row = ['user' => 'pbtest_user', 'orderId' => $orderId, 'state' => 'pending', 'basket' => '{}'];

        try {
            $this->connection->executeStatement($insert, ['id' => 'pbtest_s2b_1'] + $row);

            $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
            $this->connection->executeStatement($insert, ['id' => 'pbtest_s2b_2'] + $row);
        } finally {
            $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXORDERID = :o', ['o' => $orderId]);
        }
    }

    public function testManyContractsWithoutAnOrderAreAllowed(): void
    {
        $insert = 'INSERT INTO oe_payments_contract (OXID, OXSHOPID, OXUSERID, OXORDERID, OXSTATE, OXBASKETDATA)
                   VALUES (:id, 1, :user, NULL, :state, :basket)';

        try {
            foreach (['pbtest_s2b_n1', 'pbtest_s2b_n2'] as $id) {
                $this->connection->executeStatement($insert, ['id' => $id, 'user' => 'pbtest_user', 'state' => 'draft', 'basket' => '{}']);
            }
            self::assertSame(2, (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM oe_payments_contract WHERE OXID IN ('pbtest_s2b_n1', 'pbtest_s2b_n2')"
            ));
        } finally {
            $this->connection->executeStatement("DELETE FROM oe_payments_contract WHERE OXID LIKE 'pbtest_s2b_n%'");
        }
    }

    public function testUp_OnAMigratedShop_PlansNothing(): void
    {
        $migration = $this->migration();

        $migration->up(clone $this->schema);

        self::assertSame([], $this->plannedSql($migration));
    }

    public function testUp_WithAnOrderThatHasTwoContracts_StopsAndNamesThem(): void
    {
        $schema = clone $this->schema;
        $schema->getTable('oe_payments_contract')->dropIndex('UNQ_ORDER');
        $migration = $this->migration();
        $migration->duplicates = ['order-x' => 'contract-1,contract-2'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order-x: contract-1,contract-2');
        $migration->up($schema);
    }

    public function testDown_RestoresTheOldIndex(): void
    {
        $migration = $this->migration();

        $migration->down(clone $this->schema);

        $sql = implode("\n", $this->plannedSql($migration));
        self::assertStringContainsString('ADD INDEX IDX_ORDER (OXORDERID)', $sql);
        self::assertStringContainsString('DROP INDEX UNQ_ORDER', $sql);
    }

    private function migration(): ScriptedDuplicatesVersion20261029100000
    {
        return new ScriptedDuplicatesVersion20261029100000($this->connection, new NullLogger());
    }

    /**
     * @return list<string>
     */
    private function plannedSql(Version20261029100000 $migration): array
    {
        return array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());
    }
}

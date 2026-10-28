<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use Doctrine\Migrations\Query\Query;
use OxidEsales\PaymentBase\Migrations\Version20261028100000;
use Psr\Log\NullLogger;

/**
 * STRP-176 follow-up — the deferred order mail and its sent status live on the
 * contract row; the 1.5.0 table is gone. The conversion of the old rows is
 * pinned in the unit suite.
 */
final class OrderMailOnContractMigrationTest extends MigrationTestBase
{
    public function testContractCarriesTheThreeOrderMailColumns_AllNullable(): void
    {
        foreach (['OXORDERMAILSTATE', 'OXORDERMAILS', 'OXORDERMAILSENTAT'] as $column) {
            $this->assertColumnExists('oe_payments_contract', $column);
            self::assertFalse($this->getColumnDefinition('oe_payments_contract', $column)['notnull'], $column);
        }
    }

    public function testTheOrderMailTableIsGone(): void
    {
        self::assertFalse($this->schema->hasTable('oe_payments_order_mail'));
    }

    public function testUp_OnAMigratedShop_PlansNothing(): void
    {
        $migration = $this->migration();

        $migration->up(clone $this->schema);

        self::assertSame([], $this->plannedSql($migration));
    }

    public function testDown_RecreatesTheOldTableAndDropsTheColumns(): void
    {
        $migration = $this->migration();

        $migration->down(clone $this->schema);

        $sql = implode("\n", $this->plannedSql($migration));
        self::assertStringContainsString('CREATE TABLE oe_payments_order_mail', $sql);
        self::assertStringContainsString('DROP COLUMN OXORDERMAILSTATE', $sql);
    }

    private function migration(): Version20261028100000
    {
        return new Version20261028100000($this->connection, new NullLogger());
    }

    /**
     * @return list<string>
     */
    private function plannedSql(Version20261028100000 $migration): array
    {
        return array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());
    }
}

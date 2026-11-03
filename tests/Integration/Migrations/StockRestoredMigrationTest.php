<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use Doctrine\Migrations\Query\Query;
use OxidEsales\PaymentBase\Migrations\Version20261103110000;
use Psr\Log\NullLogger;

/**
 * VR-21 F3 - the stock restoration's claim lives on the contract row, nullable: NULL = not restored.
 */
final class StockRestoredMigrationTest extends MigrationTestBase
{
    public function testContractCarriesTheNullableStockRestoredColumn(): void
    {
        $this->assertColumnExists('oe_payments_contract', 'OXSTOCKRESTOREDAT');
        self::assertFalse($this->getColumnDefinition('oe_payments_contract', 'OXSTOCKRESTOREDAT')['notnull']);
    }

    public function testUp_OnAMigratedShop_PlansNothing(): void
    {
        $migration = new Version20261103110000($this->connection, new NullLogger());

        $migration->up(clone $this->schema);

        self::assertSame([], $this->plannedSql($migration));
    }

    public function testDown_DropsTheColumn(): void
    {
        $migration = new Version20261103110000($this->connection, new NullLogger());

        $migration->down(clone $this->schema);

        self::assertStringContainsString('DROP COLUMN OXSTOCKRESTOREDAT', implode("\n", $this->plannedSql($migration)));
    }

    /**
     * @return list<string>
     */
    private function plannedSql(Version20261103110000 $migration): array
    {
        return array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());
    }
}

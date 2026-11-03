<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use Doctrine\Migrations\Query\Query;
use OxidEsales\PaymentBase\Migrations\Version20261103130000;
use Psr\Log\NullLogger;

/**
 * VR-23 T1 - the "payment at risk" flags live in their own table, unique on contract + reason + reference.
 */
final class PaymentAtRiskMigrationTest extends MigrationTestBase
{
    public function testTheFlagTableExistsWithItsUniqueKey(): void
    {
        $this->assertTableExists('oe_payments_at_risk');
        $this->assertPrimaryKeyExists('oe_payments_at_risk');
        $this->assertIndexExists('oe_payments_at_risk', 'UQ_CONTRACT_REASON_REFERENCE');
        $this->assertColumnExists('oe_payments_at_risk', 'OXMAILEDAT');
        $this->assertColumnExists('oe_payments_at_risk', 'OXACKNOWLEDGEDAT');
    }

    public function testUpOnAMigratedShopPlansNothing(): void
    {
        $migration = new Version20261103130000($this->connection, new NullLogger());

        $migration->up(clone $this->schema);

        self::assertSame([], $this->plannedSql($migration));
    }

    public function testDownDropsTheTable(): void
    {
        $migration = new Version20261103130000($this->connection, new NullLogger());

        $migration->down(clone $this->schema);

        self::assertStringContainsString('DROP TABLE oe_payments_at_risk', implode("\n", $this->plannedSql($migration)));
    }

    /**
     * @return list<string>
     */
    private function plannedSql(Version20261103130000 $migration): array
    {
        return array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

/**
 * OPC-175 — one order, one contract.
 *
 * An order never has more than one contract, and a contract never more than
 * one order (PaymentContract keeps its order id for life). The database
 * enforces the first half: OXORDERID becomes unique. NULL (a contract without
 * an order yet) stays allowed any number of times.
 *
 * Rows that break the rule are not resolved here: which contract of an order
 * is the right one is a decision, not a migration step. The migration stops
 * and names them.
 */
class Version20261029100000 extends AbstractMigration
{
    private const CONTRACTS = 'oe_payments_contract';
    private const UNIQUE_INDEX = 'UNQ_ORDER';
    private const OLD_INDEX = 'IDX_ORDER';

    public function getDescription(): string
    {
        return 'One order, one contract: unique OXORDERID on oe_payments_contract (OPC-175)';
    }

    public function up(Schema $schema): void
    {
        $this->mapEnumColumns();

        $table = $schema->getTable(self::CONTRACTS);
        if ($table->hasIndex(self::UNIQUE_INDEX)) {
            return;
        }

        $this->refuseOrdersWithMoreThanOneContract();

        $sql = 'ALTER TABLE ' . self::CONTRACTS . ' ADD UNIQUE INDEX ' . self::UNIQUE_INDEX . ' (OXORDERID)';
        if ($table->hasIndex(self::OLD_INDEX)) {
            $sql .= ', DROP INDEX ' . self::OLD_INDEX;
        }
        $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $this->mapEnumColumns();

        if (!$schema->getTable(self::CONTRACTS)->hasIndex(self::UNIQUE_INDEX)) {
            return;
        }

        $this->addSql(
            'ALTER TABLE ' . self::CONTRACTS . ' ADD INDEX ' . self::OLD_INDEX . ' (OXORDERID), DROP INDEX '
            . self::UNIQUE_INDEX
        );
    }

    private function refuseOrdersWithMoreThanOneContract(): void
    {
        $duplicates = $this->ordersWithMoreThanOneContract();
        if ($duplicates === []) {
            return;
        }

        $lines = [];
        foreach ($duplicates as $orderId => $contractIds) {
            $lines[] = $orderId . ': ' . $contractIds;
        }

        throw new RuntimeException(
            'OPC-175: one order may have one contract only. Resolve these orders first '
            . '(order id: contract ids):' . PHP_EOL . implode(PHP_EOL, $lines)
        );
    }

    /**
     * Protected: the migration test replaces it, because the unique index makes
     * duplicates impossible in a migrated database.
     *
     * @return array<string, string> order id => comma-separated contract ids
     */
    protected function ordersWithMoreThanOneContract(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT OXORDERID, GROUP_CONCAT(OXID ORDER BY OXCREATED) AS CONTRACTS
             FROM ' . self::CONTRACTS . '
             WHERE OXORDERID IS NOT NULL
             GROUP BY OXORDERID
             HAVING COUNT(*) > 1'
        );

        $duplicates = [];
        foreach ($rows as $row) {
            $duplicates[(string) $row['OXORDERID']] = (string) $row['CONTRACTS'];
        }

        return $duplicates;
    }

    private function mapEnumColumns(): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
    }
}

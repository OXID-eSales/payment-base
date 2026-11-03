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
 * VR-21 F3 — one transaction row per (contract, type, provider transaction id):
 * a provider refund, capture or authorization is recorded once. Rows without a
 * provider id (NULL) are not affected. Shops that already hold duplicates (the
 * double-booked refunds of PayPal SEC-3 / Stripe S-4) are named and stopped; pay1
 * had none on 2026-11-03.
 */
class Version20261103100000 extends AbstractMigration
{
    private const TRANSACTIONS = 'oe_payments_transaction';
    private const UNIQUE_INDEX = 'UNQ_CONTRACT_TYPE_TXN';

    public function getDescription(): string
    {
        return 'One transaction row per provider id: unique (OXCONTRACTID, OXTYPE, OXTRANSACTIONID) (VR-21)';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if ($schema->getTable(self::TRANSACTIONS)->hasIndex(self::UNIQUE_INDEX)) {
            return;
        }

        $this->refuseDuplicates();
        $this->addSql(
            'ALTER TABLE ' . self::TRANSACTIONS . ' ADD UNIQUE INDEX ' . self::UNIQUE_INDEX
            . ' (OXCONTRACTID, OXTYPE, OXTRANSACTIONID)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if (!$schema->getTable(self::TRANSACTIONS)->hasIndex(self::UNIQUE_INDEX)) {
            return;
        }
        $this->addSql('ALTER TABLE ' . self::TRANSACTIONS . ' DROP INDEX ' . self::UNIQUE_INDEX);
    }

    private function refuseDuplicates(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT OXCONTRACTID, OXTYPE, OXTRANSACTIONID, COUNT(*) AS N
             FROM ' . self::TRANSACTIONS . '
             WHERE OXTRANSACTIONID IS NOT NULL
             GROUP BY OXCONTRACTID, OXTYPE, OXTRANSACTIONID
             HAVING COUNT(*) > 1'
        );
        if ($rows === []) {
            return;
        }

        $lines = array_map(
            static fn (array $row): string => sprintf(
                '%s %s %s: %d rows',
                (string) $row['OXCONTRACTID'],
                (string) $row['OXTYPE'],
                (string) $row['OXTRANSACTIONID'],
                (int) $row['N']
            ),
            $rows
        );
        throw new RuntimeException(
            'VR-21: a provider transaction is recorded once per contract. Resolve these duplicates first '
            . '(contract type provider-id):' . PHP_EOL . implode(PHP_EOL, $lines)
        );
    }
}

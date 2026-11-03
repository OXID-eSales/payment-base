<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * VR-21 F3 - a fully refunded order's stock goes back once. OXSTOCKRESTOREDAT on the contract (one order, one
 * contract) is the claim the stock restoration sets before it restores; NULL = not restored. On the contract, not on
 * oxorder: the module owns its tables and never adds columns to a core one. Contracts refunded before this
 * migration were restocked by the provider modules' own service and keep NULL; nothing re-runs for them, because
 * restoration is asked only when a refund is booked.
 */
final class Version20261103120000 extends AbstractMigration
{
    private const CONTRACTS = 'oe_payments_contract';
    private const COLUMN = 'OXSTOCKRESTOREDAT';

    public function getDescription(): string
    {
        return 'Stock restored once per order: OXSTOCKRESTOREDAT on oe_payments_contract (VR-21)';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if ($schema->getTable(self::CONTRACTS)->hasColumn(self::COLUMN)) {
            return;
        }

        $this->addSql(
            'ALTER TABLE ' . self::CONTRACTS . ' ADD COLUMN ' . self::COLUMN
            . " DATETIME NULL COMMENT 'When the order stock was restored after a full refund; NULL = not yet'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if (!$schema->getTable(self::CONTRACTS)->hasColumn(self::COLUMN)) {
            return;
        }

        $this->addSql('ALTER TABLE ' . self::CONTRACTS . ' DROP COLUMN ' . self::COLUMN);
    }
}

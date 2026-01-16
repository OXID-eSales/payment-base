<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Create oe_payments_transaction table (MASTER - Provider-Agnostic)
 *
 * Core transaction data - present for ALL transactions.
 * Master table in master-detail pattern for performance optimization.
 */
final class Version20251031140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create oe_payments_transaction table - master transaction table (provider-agnostic)';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        $this->createPaymentTransactionTable($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('oe_payments_transaction')) {
            $schema->dropTable('oe_payments_transaction');
        }
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentTransactionTable(Schema $schema): void
    {
        $tableName = 'oe_payments_transaction';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        // Primary key
        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Transaction ID'
        ]);

        $table->addColumn('OXSHOPID', Types::INTEGER, [
            'notnull' => true,
            'comment' => 'Shop ID'
        ]);

        // References
        $table->addColumn('OXORDERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'FK to oxorder.OXID'
        ]);

        $table->addColumn('OXCONTRACTID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false,
            'comment' => 'FK to oe_payments_contract.OXID (contract-aware)'
        ]);

        // Provider identification (provider-agnostic)
        $table->addColumn('OXPROVIDER', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'stripe, paypal, unzer, amazon, adyen, klarna'
        ]);

        $table->addColumn('OXPROVIDERORDERID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) NULL',
            'notnull' => false,
            'comment' => 'Provider order/payment ID'
        ]);

        $table->addColumn('OXTRANSACTIONID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) NULL',
            'notnull' => false,
            'comment' => 'Provider transaction ID'
        ]);

        // Transaction basics
        $table->addColumn('OXTYPE', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'authorization, capture, refund, void'
        ]);

        $table->addColumn('OXSTATUS', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'pending, completed, failed, cancelled'
        ]);

        $table->addColumn('OXAMOUNT', Types::DECIMAL, [
            'precision' => 10,
            'scale' => 2,
            'notnull' => true,
            'comment' => 'Transaction amount'
        ]);

        $table->addColumn('OXCURRENCY', Types::STRING, [
            'columnDefinition' => 'VARCHAR(3) NOT NULL',
            'comment' => 'Currency code (ISO 4217)'
        ]);

        // Payment method
        $table->addColumn('OXPAYMENTMETHODID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(64) NULL',
            'notnull' => false,
            'comment' => 'Payment method ID'
        ]);

        $table->addColumn('OXPAYMENTMETHODTYPE', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NULL',
            'notnull' => false,
            'comment' => 'Payment method type (card, sepa_debit, etc.)'
        ]);

        // Relationships
        $table->addColumn('OXPARENTTRANSACTIONID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false,
            'comment' => 'FK to parent transaction (for refunds/voids)'
        ]);

        // Timestamps
        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL',
            'comment' => 'Transaction creation timestamp'
        ]);

        $table->addColumn('OXUPDATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL',
            'comment' => 'Last update timestamp'
        ]);

        // Primary key
        $table->setPrimaryKey(['OXID']);

        // Indexes
        $table->addIndex(['OXORDERID'], 'IDX_ORDER');
        $table->addIndex(['OXCONTRACTID'], 'IDX_CONTRACT');
        $table->addIndex(['OXPROVIDERORDERID'], 'IDX_PROVIDER_ORDER');
        $table->addIndex(['OXTRANSACTIONID'], 'IDX_TRANSACTION_ID');
        $table->addIndex(['OXTYPE', 'OXSTATUS'], 'IDX_TYPE_STATUS');
        $table->addIndex(['OXPARENTTRANSACTIONID'], 'IDX_PARENT');

        // Foreign keys
        // Note: FK to oxorder removed to allow TRUNCATE during demodata installation
        // Referential integrity maintained at application level
        // Index IDX_ORDER is sufficient for query performance

        $table->addForeignKeyConstraint(
            'oe_payments_contract',
            ['OXCONTRACTID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_CONTRACT'
        );

        $table->addForeignKeyConstraint(
            'oe_payments_transaction',
            ['OXPARENTTRANSACTIONID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_PARENT_TX'
        );

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'latin1');
        $table->addOption('collate', 'latin1_general_ci');
        $table->addOption('comment', 'Payment transaction master table - Provider-agnostic v4.0');
    }
}

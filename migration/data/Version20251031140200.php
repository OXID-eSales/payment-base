<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Create support tables (customer, idempotency, sessions, webhooklogs)
 *
 * Essential support tables for payment lifecycle management.
 * Note: order_state table was removed - capture/refund tracking is now in oe_payments_contract.
 */
final class Version20251031140200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payment support tables - customer, idempotency, sessions, webhook_logs (provider-agnostic)';
    }

    /**
     * Run migration up
     *
     * @SuppressWarnings(PHPMD)
     */
    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        $this->createPaymentCustomerTable($schema);
        $this->createPaymentIdempotencyTable($schema);
        $this->createPaymentSessionsTable($schema);
        $this->createPaymentWebhookLogsTable($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('oe_payments_webhooklogs')) {
            $schema->dropTable('oe_payments_webhooklogs');
        }
        if ($schema->hasTable('oe_payments_sessions')) {
            $schema->dropTable('oe_payments_sessions');
        }
        if ($schema->hasTable('oe_payments_idempotency')) {
            $schema->dropTable('oe_payments_idempotency');
        }
        if ($schema->hasTable('oe_payments_customer')) {
            $schema->dropTable('oe_payments_customer');
        }
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentCustomerTable(Schema $schema): void
    {
        $tableName = 'oe_payments_customer';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL'
        ]);

        $table->addColumn('OXUSERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'FK to oxuser.OXID (1:1)'
        ]);

        $table->addColumn('OXPAYMENTCUSTOMERID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) NULL',
            'notnull' => false,
            'comment' => 'Provider customer ID'
        ]);

        $table->addColumn('OXDEFAULTPAYMENTMETHOD', Types::STRING, [
            'columnDefinition' => 'VARCHAR(64) NULL',
            'notnull' => false
        ]);

        $table->addColumn('OXSAVEDPAYMENTMETHODS', Types::TEXT, [
            'notnull' => false,
            'comment' => 'JSON array of saved payment methods'
        ]);

        $table->addColumn('OXBILLINGAGREEMENT', Types::BOOLEAN, [
            'default' => false
        ]);

        $table->addColumn('OXLASTPAYMENTDATE', Types::DATETIME_MUTABLE, [
            'notnull' => false
        ]);

        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->addColumn('OXUPDATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->setPrimaryKey(['OXID']);
        $table->addUniqueIndex(['OXUSERID'], 'UK_USER');

        // Note: FK to oxuser removed to allow TRUNCATE during demodata installation
        // Referential integrity maintained at application level
        // Unique index UK_USER ensures 1:1 relationship and query performance

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentIdempotencyTable(Schema $schema): void
    {
        $tableName = 'oe_payments_idempotency';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL'
        ]);

        $table->addColumn('OXKEY', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Idempotency key'
        ]);

        $table->addColumn('OXORDERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL'
        ]);

        $table->addColumn('OXOPERATION', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'createPayment, capturePayment, refundPayment'
        ]);

        $table->addColumn('OXRESULT', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Cached result (JSON)'
        ]);

        $table->addColumn('OXSTATUS', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NULL',
            'notnull' => false,
            'comment' => 'processing, completed, failed'
        ]);

        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->addColumn('OXEXPIRES', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->setPrimaryKey(['OXID']);
        $table->addUniqueIndex(['OXKEY'], 'UK_KEY');
        $table->addIndex(['OXEXPIRES'], 'IDX_EXPIRES');
        $table->addIndex(['OXORDERID', 'OXOPERATION'], 'IDX_ORDER_OPERATION');

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentSessionsTable(Schema $schema): void
    {
        $tableName = 'oe_payments_sessions';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL'
        ]);

        $table->addColumn('OXPROVIDER', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Payment provider'
        ]);

        $table->addColumn('OXSESSIONID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Provider session ID'
        ]);

        $table->addColumn('OXUSERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false
        ]);

        $table->addColumn('OXBASKETID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false
        ]);

        $table->addColumn('OXDATA', Types::TEXT, [
            'notnull' => false,
            'comment' => 'JSON session data'
        ]);

        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->addColumn('OXEXPIRES', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL'
        ]);

        $table->setPrimaryKey(['OXID']);
        $table->addIndex(['OXSESSIONID'], 'IDX_SESSION');
        $table->addIndex(['OXUSERID'], 'IDX_USER');
        $table->addIndex(['OXEXPIRES'], 'IDX_EXPIRES');

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentWebhookLogsTable(Schema $schema): void
    {
        $tableName = 'oe_payments_webhooklogs';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Log entry ID'
        ]);

        $table->addColumn('OXEVENTID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Provider webhook event ID (unique)'
        ]);

        $table->addColumn('OXEVENTTYPE', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) NULL',
            'notnull' => false,
            'comment' => 'Event type (payment_intent.succeeded, etc.)'
        ]);

        $table->addColumn('OXPROVIDER', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false,
            'comment' => 'Payment provider: stripe, paypal, unzer, adyen, klarna, amazonpay'
        ]);

        $table->addColumn('OXCONTRACTID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false,
            'comment' => 'FK to oe_payments_contract.OXID'
        ]);

        $table->addColumn('OXSTATUS', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'received, processed, failed'
        ]);

        $table->addColumn('OXRECEIVEDAT', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL',
            'comment' => 'When webhook was received'
        ]);

        $table->addColumn('OXERROR', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Error message if processing failed'
        ]);

        $table->addColumn('OXPAYLOAD', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Webhook payload (JSON)'
        ]);

        $table->addColumn('OXPROCESSEDAT', Types::DATETIME_MUTABLE, [
            'notnull' => false,
            'comment' => 'When webhook was processed'
        ]);

        $table->setPrimaryKey(['OXID']);
        $table->addUniqueIndex(['OXEVENTID'], 'UK_EVENT_ID');
        $table->addIndex(['OXCONTRACTID'], 'IDX_CONTRACT');
        $table->addIndex(['OXSTATUS'], 'IDX_STATUS');
        $table->addIndex(['OXRECEIVEDAT'], 'IDX_RECEIVED_AT');

        $table->addForeignKeyConstraint(
            'oe_payments_contract',
            ['OXCONTRACTID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_OE_WEBHOOK_CONTRACT'
        );

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('comment', 'Webhook event logs for payment providers');
    }
}

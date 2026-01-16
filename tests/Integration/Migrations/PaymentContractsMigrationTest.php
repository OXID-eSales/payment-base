<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OxidEsales\PaymentComponent\Migrations\Version20251031140000;
use Psr\Log\NullLogger;

class PaymentContractsMigrationTest extends MigrationTestBase
{
    private const TABLE_CONTRACTS = 'oe_payments_contract';
    private const TABLE_CONDITIONS = 'oe_payments_contract_condition';
    private const TABLE_WEBHOOK_LOGS = 'oe_payments_webhook_logs';

    /** @test */
    public function migration_creates_payment_contracts_table(): void
    {
        $schema = new Schema();
        $migration = new Version20251031140000($this->connection, new NullLogger());

        $migration->up($schema);
        $this->refreshSchema();

        $this->assertTableExists(self::TABLE_CONTRACTS);
    }

    /** @test */
    public function contracts_table_has_correct_columns(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXSHOPID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXUSERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXORDERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXSTATE');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXBASKETDATA');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXPROVIDER');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXPROVIDERORDERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXCREATED');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXUPDATED');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXFULFILLEDAT');
    }

    /** @test */
    public function contracts_table_has_correct_column_types(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXSHOPID', 'integer');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXUSERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXORDERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXSTATE', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXBASKETDATA', 'text');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXPROVIDER', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXPROVIDERORDERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXCREATED', 'datetime');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXUPDATED', 'datetime');
    }

    /** @test */
    public function contract_table_has_primary_key(): void
    {
        $this->ensureMigrationRan();

        $this->assertPrimaryKeyExists(self::TABLE_CONTRACTS);
    }

    /** @test */
    public function contracts_table_has_required_indexes(): void
    {
        $this->ensureMigrationRan();

        $this->assertIndexExists(self::TABLE_CONTRACTS, 'IDX_USER');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'IDX_STATE');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'IDX_PROVIDER_ORDER');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'IDX_ORDER');
    }

    /** @test */
    public function migration_is_idempotent(): void
    {
        $schema = new Schema();
        $migration = new Version20251031140000($this->connection, new NullLogger());

        $migration->up($schema);
        $migration->up($schema);
        $this->refreshSchema();

        $this->assertTableExists(self::TABLE_CONTRACTS);
    }

    /** @test */
    public function basket_column_has_correct_comment(): void
    {
        $this->ensureMigrationRan();

        $columnDef = $this->getColumnDefinition(self::TABLE_CONTRACTS, 'OXBASKETDATA');
        $this->assertStringContainsString('JSON', $columnDef['comment'] ?? '');
    }

    private function ensureMigrationRan(): void
    {
        if (!$this->schema->hasTable(self::TABLE_CONTRACTS)) {
            $schema = new Schema();
            $migration = new Version20251031140000($this->connection, new NullLogger());
            $migration->up($schema);
            $this->refreshSchema();
        }
    }

    public function tearDown(): void
    {
        // Don't drop tables - they are shared infrastructure needed by other tests
        // Migration tests should verify migrations work, but not break the test environment
        // Test isolation is achieved through test data cleanup, not table dropping

        parent::tearDown();
    }
}

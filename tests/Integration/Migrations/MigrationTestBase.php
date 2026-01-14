<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;

/**
 * Base class for migration tests
 */
class MigrationTestBase extends IntegrationTestCase
{
    protected Connection $connection;
    protected Schema $schema;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $queryBuilderFactory = $container->get(QueryBuilderFactoryInterface::class);
        $this->connection = $queryBuilderFactory->create()->getConnection();

        // Register enum type mapping to handle ENUM columns in existing database
        $platform = $this->connection->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('enum', 'string');

        $this->schema = $this->connection->getSchemaManager()->createSchema();
    }

    /**
     * Assert that a table exists
     */
    protected function assertTableExists(string $tableName, string $message = ''): void
    {
        $this->assertTrue(
            $this->schema->hasTable($tableName),
            $message ?: "Table '{$tableName}' should exist"
        );
    }

    /**
     * Assert that a column exists in a table
     */
    protected function assertColumnExists(string $tableName, string $columnName, string $message = ''): void
    {
        $this->assertTrue(
            $this->schema->getTable($tableName)->hasColumn($columnName),
            $message ?: "Column '{$columnName}' should exist in table '{$tableName}'"
        );
    }

    /**
     * Assert column type
     */
    protected function assertColumnType(string $tableName, string $columnName, string $expectedType, string $message = ''): void
    {
        $table = $this->schema->getTable($tableName);
        $column = $table->getColumn($columnName);
        $actualType = $column->getType()->getName();

        $this->assertEquals(
            $expectedType,
            $actualType,
            $message ?: "Column '{$columnName}' in table '{$tableName}' should be of type '{$expectedType}', got '{$actualType}'"
        );
    }

    /**
     * Assert that an index exists
     */
    protected function assertIndexExists(string $tableName, string $indexName, string $message = ''): void
    {
        $table = $this->schema->getTable($tableName);
        $this->assertTrue(
            $table->hasIndex($indexName),
            $message ?: "Index '{$indexName}' should exist in table '{$tableName}'"
        );
    }

    /**
     * Assert that a primary key exists
     */
    protected function assertPrimaryKeyExists(string $tableName, string $message = ''): void
    {
        $table = $this->schema->getTable($tableName);
        $this->assertTrue(
            $table->hasPrimaryKey(),
            $message ?: "Table '{$tableName}' should have a primary key"
        );
    }

    /**
     * Get column definition
     */
    protected function getColumnDefinition(string $tableName, string $columnName): array
    {
        $table = $this->schema->getTable($tableName);
        $column = $table->getColumn($columnName);

        return [
            'name' => $column->getName(),
            'type' => $column->getType()->getName(),
            'length' => $column->getLength(),
            'notnull' => $column->getNotnull(),
            'default' => $column->getDefault(),
            'comment' => $column->getComment(),
        ];
    }

    /**
     * Refresh schema after migration
     */
    protected function refreshSchema(): void
    {
        $this->schema = $this->connection->getSchemaManager()->createSchema();
    }
}

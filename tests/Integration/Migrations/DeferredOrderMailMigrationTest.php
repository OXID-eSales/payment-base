<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use OxidEsales\PaymentBase\Migrations\Version20261026100000;

/**
 * STRP-176 — oe_payments_order_mail, where the order mails of an unpaid order
 * wait for the payment.
 */
final class DeferredOrderMailMigrationTest extends MigrationTestBase
{
    public function testTableExistsWithEveryColumnTheRepositoryWrites(): void
    {
        $this->assertTableExists('oe_payments_order_mail');

        foreach (
            [
                'OXID', 'OXORDERID', 'OXSHOPID', 'OXKIND', 'OXFROM', 'OXFROMNAME', 'OXRECIPIENTS', 'OXREPLYTO',
                'OXCC', 'OXBCC', 'OXSUBJECT', 'OXBODY', 'OXALTBODY', 'OXTIMESTAMP', 'OXCLAIMEDAT',
            ] as $column
        ) {
            $this->assertColumnExists('oe_payments_order_mail', $column);
        }
    }

    public function testTableIsUtf8mb4SoTheRenderedMailSurvives(): void
    {
        // The contract table is latin1; a mail body there would lose € and umlauts.
        $collation = $this->connection->fetchOne(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['oe_payments_order_mail']
        );

        self::assertSame('utf8mb4_unicode_ci', $collation);
    }

    public function testOrdersMailsAreLookedUpByAnIndex(): void
    {
        $indexes = $this->schema->getTable('oe_payments_order_mail')->getIndexes();

        self::assertArrayHasKey('idx_order_mail_order', $indexes);
        self::assertSame(['OXORDERID'], $indexes['idx_order_mail_order']->getColumns());
    }

    public function testUp_WhenTheTableExists_ChangesNothing(): void
    {
        // Re-running on an installed shop must not fail or touch the table.
        $migration = new Version20261026100000($this->connection, new \Psr\Log\NullLogger());
        $schema = clone $this->schema;

        $migration->up($schema);

        self::assertEquals(
            $this->schema->getTable('oe_payments_order_mail'),
            $schema->getTable('oe_payments_order_mail')
        );
    }
}

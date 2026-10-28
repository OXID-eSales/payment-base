<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use OxidEsales\PaymentBase\Migrations\Version20261026100000;
use Psr\Log\NullLogger;

/**
 * STRP-176 (1.5.0) — oe_payments_order_mail. Released, so it still runs on
 * every install that comes from before 1.5.0; Version20261028100000 moves its
 * rows onto the contract and drops it right after.
 */
final class DeferredOrderMailMigrationTest extends MigrationTestBase
{
    public function testUp_OnASchemaWithoutTheTable_StillCreatesItWithEveryColumnTheFollowUpReads(): void
    {
        $schema = clone $this->schema;
        if ($schema->hasTable('oe_payments_order_mail')) {
            $schema->dropTable('oe_payments_order_mail');
        }

        (new Version20261026100000($this->connection, new NullLogger()))->up($schema);

        $table = $schema->getTable('oe_payments_order_mail');
        foreach (
            [
                'OXID', 'OXORDERID', 'OXSHOPID', 'OXKIND', 'OXFROM', 'OXFROMNAME', 'OXRECIPIENTS', 'OXREPLYTO',
                'OXCC', 'OXBCC', 'OXSUBJECT', 'OXBODY', 'OXALTBODY', 'OXTIMESTAMP', 'OXCLAIMEDAT',
            ] as $column
        ) {
            self::assertTrue($table->hasColumn($column), $column);
        }
    }
}

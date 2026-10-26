<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * STRP-176 — the order mails of an order that is not paid yet.
 *
 * Early order creation renders the customer and owner mail in finalizeOrder(), before the customer pays at the PSP.
 * They wait here and are sent when the contract commits. utf8mb4, unlike the latin1 contract table: the rows carry
 * rendered HTML with currency signs and customer names in any script.
 */
final class Version20261026100000 extends AbstractMigration
{
    private const TABLE_NAME = 'oe_payments_order_mail';

    public function getDescription(): string
    {
        return 'Create oe_payments_order_mail - order mails deferred until the payment is confirmed (STRP-176)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->createTable(self::TABLE_NAME);
        $this->addIdentifierColumns($table);
        $this->addEnvelopeColumns($table);
        $this->addContentColumns($table);

        $table->setPrimaryKey(['OXID']);
        $table->addIndex(['OXORDERID'], 'IDX_ORDER_MAIL_ORDER');
        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collate', 'utf8mb4_unicode_ci');
        $table->addOption('comment', 'Order mails deferred until the payment is confirmed (STRP-176)');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE_NAME)) {
            $schema->dropTable(self::TABLE_NAME);
        }
    }

    private function addIdentifierColumns(Table $table): void
    {
        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
        ]);
        $table->addColumn('OXORDERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'oxorder.OXID',
        ]);
        $table->addColumn('OXSHOPID', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('OXKIND', Types::STRING, [
            'columnDefinition' => 'VARCHAR(16) NOT NULL',
            'comment' => 'customer or owner',
        ]);
        $table->addColumn('OXTIMESTAMP', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
    }

    private function addEnvelopeColumns(Table $table): void
    {
        $table->addColumn('OXFROM', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('OXFROMNAME', Types::STRING, ['length' => 255, 'notnull' => true, 'default' => '']);
        foreach (['OXRECIPIENTS', 'OXREPLYTO', 'OXCC', 'OXBCC'] as $column) {
            $table->addColumn($column, Types::TEXT, [
                'notnull' => true,
                'comment' => 'JSON list of [address, name]',
            ]);
        }
    }

    private function addContentColumns(Table $table): void
    {
        $table->addColumn('OXSUBJECT', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('OXBODY', Types::TEXT, ['columnDefinition' => 'MEDIUMTEXT NOT NULL']);
        $table->addColumn('OXALTBODY', Types::TEXT, ['columnDefinition' => 'MEDIUMTEXT NOT NULL']);
    }
}

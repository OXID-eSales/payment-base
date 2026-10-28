<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * STRP-176 follow-up — the deferred order mails move onto their contract; oe_payments_order_mail (1.5.0) goes.
 *
 * Three columns on oe_payments_contract: OXORDERMAILSTATE (pending, sending, sent, failed, dropped; NULL = nothing
 * deferred), OXORDERMAILS (the rendered mails as JSON until they are sent) and OXORDERMAILSENTAT. The JSON is
 * written with escaped unicode, so it is plain ASCII and survives the latin1 table byte for byte.
 *
 * Mails still waiting in the old table are moved to the contract of their order; rows whose order or contract no
 * longer exists are dropped with the table. Everything is raw SQL in up(), in order: Doctrine runs a migration's own
 * statements before the schema diff, so columns added through $schema would not exist yet for the move.
 *
 * Frozen on purpose: the JSON shape is written here, not through a module class, so this migration keeps doing
 * what it did when the module changes. down() recreates the empty 1.5.0 table; mails waiting on contracts are lost.
 */
final class Version20261028100000 extends AbstractMigration
{
    private const CONTRACTS = 'oe_payments_contract';
    private const LEGACY_TABLE = 'oe_payments_order_mail';

    public function getDescription(): string
    {
        return 'Keep deferred order mails and their sent status on oe_payments_contract, drop oe_payments_order_mail '
            . '(STRP-176)';
    }

    public function up(Schema $schema): void
    {
        $this->mapEnumColumns();

        if (!$schema->getTable(self::CONTRACTS)->hasColumn('OXORDERMAILSTATE')) {
            $this->addSql(
                'ALTER TABLE ' . self::CONTRACTS . "
                 ADD COLUMN OXORDERMAILSTATE VARCHAR(16) NULL
                     COMMENT 'Order mail: pending, sending, sent, failed, dropped; NULL = nothing deferred',
                 ADD COLUMN OXORDERMAILS LONGTEXT NULL
                     COMMENT 'Rendered order mails (ASCII JSON) until they are sent',
                 ADD COLUMN OXORDERMAILSENTAT DATETIME NULL
                     COMMENT 'When the order mails went out'"
            );
        }

        if (!$schema->hasTable(self::LEGACY_TABLE)) {
            return;
        }

        foreach ($this->waitingMailsByContract() as $contractId => [$state, $json]) {
            $this->addSql(
                'UPDATE ' . self::CONTRACTS . ' SET OXORDERMAILSTATE = ?, OXORDERMAILS = ?
                 WHERE OXID = ? AND OXORDERMAILSTATE IS NULL',
                [$state, $json, $contractId]
            );
        }
        $this->addSql('DROP TABLE ' . self::LEGACY_TABLE);
    }

    public function down(Schema $schema): void
    {
        $this->mapEnumColumns();

        if (!$schema->hasTable(self::LEGACY_TABLE)) {
            $this->addSql($this->legacyTableDefinition());
        }
        if ($schema->getTable(self::CONTRACTS)->hasColumn('OXORDERMAILSTATE')) {
            $this->addSql(
                'ALTER TABLE ' . self::CONTRACTS . '
                 DROP COLUMN OXORDERMAILSTATE, DROP COLUMN OXORDERMAILS, DROP COLUMN OXORDERMAILSENTAT'
            );
        }
    }

    /**
     * The 1.5.0 rows, grouped by the contract of their order: [state, JSON list of mails]. A row claimed but never
     * deleted is a send that died halfway; its contract is "failed", so nothing sends it a second time by itself.
     *
     * @param list<array<string, mixed>> $rows CONTRACTID plus the old table's columns, customer before owner
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function groupByContract(array $rows): array
    {
        $mails = [];
        $claimed = [];
        foreach ($rows as $row) {
            $contractId = (string) $row['CONTRACTID'];
            $mails[$contractId][] = [
                'kind' => (string) $row['OXKIND'],
                'from' => (string) $row['OXFROM'],
                'fromName' => (string) $row['OXFROMNAME'],
                'recipients' => self::addressList($row['OXRECIPIENTS']),
                'replyTo' => self::addressList($row['OXREPLYTO']),
                'cc' => self::addressList($row['OXCC']),
                'bcc' => self::addressList($row['OXBCC']),
                'subject' => (string) $row['OXSUBJECT'],
                'body' => (string) $row['OXBODY'],
                'altBody' => (string) $row['OXALTBODY'],
            ];
            $claimed[$contractId] = ($claimed[$contractId] ?? false) || $row['OXCLAIMEDAT'] !== null;
        }

        $moved = [];
        foreach ($mails as $contractId => $list) {
            $moved[$contractId] = [
                $claimed[$contractId] ? 'failed' : 'pending',
                json_encode($list, JSON_THROW_ON_ERROR),
            ];
        }

        return $moved;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function waitingMailsByContract(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.OXID AS CONTRACTID, m.*
             FROM ' . self::LEGACY_TABLE . ' m
             JOIN ' . self::CONTRACTS . ' c ON c.OXORDERID = m.OXORDERID
             JOIN oxorder o ON o.OXID = m.OXORDERID
             ORDER BY c.OXID, m.OXKIND'
        );
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::LEGACY_TABLE);
        $this->write(sprintf(
            'oe_payments_order_mail: %d of %d mails moved to their contract, the rest belong to no order or contract',
            count($rows),
            $total
        ));

        return self::groupByContract($rows);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function addressList(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $pairs = [];
        foreach ($decoded as $pair) {
            if (is_array($pair) && isset($pair[0])) {
                $pairs[] = [(string) $pair[0], (string) ($pair[1] ?? '')];
            }
        }

        return $pairs;
    }

    /**
     * The table as Version20261026100000 created it.
     */
    private function legacyTableDefinition(): string
    {
        return 'CREATE TABLE ' . self::LEGACY_TABLE . " (
            OXID CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL,
            OXORDERID CHAR(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'oxorder.OXID',
            OXSHOPID INT NOT NULL,
            OXKIND VARCHAR(16) NOT NULL COMMENT 'customer or owner',
            OXTIMESTAMP TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            OXCLAIMEDAT DATETIME DEFAULT NULL
                COMMENT 'set while a sender holds the mail (return and webhook leg race); NULL = waiting',
            OXFROM VARCHAR(255) NOT NULL,
            OXFROMNAME VARCHAR(255) NOT NULL DEFAULT '',
            OXRECIPIENTS LONGTEXT NOT NULL COMMENT 'JSON list of [address, name]',
            OXREPLYTO LONGTEXT NOT NULL COMMENT 'JSON list of [address, name]',
            OXCC LONGTEXT NOT NULL COMMENT 'JSON list of [address, name]',
            OXBCC LONGTEXT NOT NULL COMMENT 'JSON list of [address, name]',
            OXSUBJECT VARCHAR(255) NOT NULL,
            OXBODY MEDIUMTEXT NOT NULL,
            OXALTBODY MEDIUMTEXT NOT NULL,
            PRIMARY KEY (OXID),
            KEY IDX_ORDER_MAIL_ORDER (OXORDERID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Order mails deferred until the payment is confirmed (STRP-176)'";
    }

    /**
     * $schema is introspected lazily and core tables carry ENUM columns DBAL has no type for (as
     * Version20261026100000 guards).
     */
    private function mapEnumColumns(): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
    }
}

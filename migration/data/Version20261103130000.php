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
 * VR-23 T1 - "payment at risk" flags (authorization expired, capture denied or reversed, dispute opened, late
 * money the provider would not return). Own table, not a column on the contract: several reasons can be open on
 * one contract, the admin filters orders by it, and raising a flag from a webhook must not fight the contract's
 * version lock. The unique key (contract, reason, provider reference) makes raising idempotent; OXMAILEDAT is the
 * owner mail's exactly-once claim. A flag is acknowledged by the merchant, never deleted.
 */
final class Version20261103130000 extends AbstractMigration
{
    private const TABLE = 'oe_payments_at_risk';

    public function getDescription(): string
    {
        return 'Create oe_payments_at_risk: payment-at-risk flags per contract (VR-23)';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $this->addSql(
            'CREATE TABLE ' . self::TABLE . " (
                OXID CHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXCONTRACTID CHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXORDERID CHAR(32) COLLATE latin1_general_ci NULL COMMENT 'NULL while the contract has no order',
                OXREASON VARCHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXREFERENCE VARCHAR(128) COLLATE latin1_general_ci NOT NULL DEFAULT '' COMMENT 'Provider id of the event behind the flag',
                OXRAISEDAT DATETIME NOT NULL,
                OXMAILEDAT DATETIME NULL COMMENT 'Owner mail claimed/sent; NULL = not mailed',
                OXACKNOWLEDGEDAT DATETIME NULL COMMENT 'Merchant decided; NULL = open',
                PRIMARY KEY (OXID),
                UNIQUE KEY UQ_CONTRACT_REASON_REFERENCE (OXCONTRACTID, OXREASON, OXREFERENCE),
                INDEX IDX_OPEN_ORDER (OXACKNOWLEDGEDAT, OXORDERID)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci"
        );
    }

    public function down(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $this->addSql('DROP TABLE ' . self::TABLE);
    }
}

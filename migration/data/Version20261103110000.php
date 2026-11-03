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
 * VR-20 R3 - the shop's own secrets, starting with the one that signs return tokens. Before, each
 * provider derived its token key from an API key (Stripe even fell back to the webhook secret), so
 * rotating a provider key silently invalidated every return URL in flight and the key doubled as
 * the token key. The first `return_token` secret is generated here (32 random bytes, hex).
 */
class Version20261103110000 extends AbstractMigration
{
    private const TABLE = 'oe_payments_secrets';
    private const SECRET_BYTES = 32;

    public function getDescription(): string
    {
        return 'Create oe_payments_secrets with the first return-token secret (VR-20 R3)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $this->addSql(
            'CREATE TABLE ' . self::TABLE . ' (
                OXID CHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXPURPOSE VARCHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXSECRET VARCHAR(128) COLLATE latin1_general_ci NOT NULL,
                OXCREATEDAT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (OXID),
                INDEX IDX_PURPOSE_CREATED (OXPURPOSE, OXCREATEDAT)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci'
        );
        $this->addSql(
            'INSERT INTO ' . self::TABLE . ' (OXID, OXPURPOSE, OXSECRET) VALUES (?, ?, ?)',
            [bin2hex(random_bytes(16)), 'return_token', bin2hex(random_bytes(self::SECRET_BYTES))]
        );
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }
        $this->addSql('DROP TABLE ' . self::TABLE);
    }
}

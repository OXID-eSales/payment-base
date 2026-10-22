<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessTokenHash;
use OxidEsales\PaymentBase\Migrations\Version20261022100000;
use Psr\Log\NullLogger;

/**
 * Sprint 138b / B2: plaintext `headless_token` values already in the database
 * are hashed in place; the client still holds the plaintext token, so open
 * attempts keep working.
 */
final class HeadlessTokenHashMigrationTest extends MigrationTestBase
{
    /**
     * Built at runtime, not a literal: a 32-hex token in the source would read
     * like a committed credential to the secret scanner (sprint 138a rules).
     */
    private static function plaintextToken(): string
    {
        return bin2hex(str_repeat("\x5a", 16));
    }

    public function testUp_PlaintextTokenInMetadata_IsReplacedByItsHash(): void
    {
        $this->insertContract('hash-mig-1', ['headless_token' => self::plaintextToken(), 'basket_id' => 'ub-1']);

        $this->runMigration();

        $metadata = $this->metadataOf('hash-mig-1');
        self::assertSame(hash('sha256', self::plaintextToken()), $metadata['headless_token']);
        self::assertTrue(HeadlessTokenHash::fromStored($metadata['headless_token'])->matches(self::plaintextToken()));
        self::assertSame('ub-1', $metadata['basket_id'], 'other metadata is untouched');
    }

    public function testUp_RunTwice_DoesNotHashTheHashAgain(): void
    {
        $this->insertContract('hash-mig-2', ['headless_token' => self::plaintextToken()]);

        $this->runMigration();
        $this->runMigration();

        self::assertSame(hash('sha256', self::plaintextToken()), $this->metadataOf('hash-mig-2')['headless_token']);
    }

    public function testUp_ContractWithoutTokenOrMetadata_IsLeftAlone(): void
    {
        $this->insertContract('hash-mig-3', ['basket_id' => 'ub-3']);
        $this->insertContract('hash-mig-4', null);

        $this->runMigration();

        self::assertSame(['basket_id' => 'ub-3'], $this->metadataOf('hash-mig-3'));
        self::assertNull($this->connection->fetchOne('SELECT OXMETADATA FROM oe_payments_contract WHERE OXID = ?', ['hash-mig-4']));
    }

    private function runMigration(): void
    {
        $migration = new Version20261022100000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @param array<string, string>|null $metadata
     */
    private function insertContract(string $id, ?array $metadata): void
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert('oe_payments_contract', [
            'OXID' => $id,
            'OXSHOPID' => 1,
            'OXUSERID' => 'user-1',
            'OXSTATE' => 'pending',
            'OXBASKETDATA' => '{}',
            'OXCONDITIONS' => '[]',
            'OXMETADATA' => $metadata === null ? null : json_encode($metadata),
            'OXCREATED' => $now,
            'OXUPDATED' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(string $id): array
    {
        $json = $this->connection->fetchOne('SELECT OXMETADATA FROM oe_payments_contract WHERE OXID = ?', [$id]);

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}

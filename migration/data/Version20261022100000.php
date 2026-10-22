<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 138b — the headless contract token is stored as its SHA-256 from now on. Contracts started before that
 * still carry the plaintext token in OXMETADATA.headless_token; it is hashed in place. The client holds the
 * plaintext token, so an attempt that is open during the upgrade can still return or cancel.
 *
 * Done in PHP, not with JSON_SET(): OXMETADATA is a latin1 longtext and MySQL would re-order and re-encode the
 * whole document. Idempotent: a value that already is a 64-character hex digest is skipped.
 */
final class Version20261022100000 extends AbstractMigration
{
    private const TOKEN_KEY = 'headless_token';
    private const SHA256_HEX = '/^[0-9a-f]{64}$/';

    public function getDescription(): string
    {
        return 'Hash plaintext headless_token values in oe_payments_contract.OXMETADATA (sprint 138b)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT OXID, OXMETADATA FROM oe_payments_contract WHERE OXMETADATA LIKE ?',
            ['%"' . self::TOKEN_KEY . '"%']
        );

        foreach ($rows as $row) {
            $hashed = $this->withHashedToken((string) $row['OXMETADATA']);
            if ($hashed !== null) {
                $this->addSql('UPDATE oe_payments_contract SET OXMETADATA = ? WHERE OXID = ?', [$hashed, $row['OXID']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('A hash cannot be turned back into the token.');
    }

    /**
     * @return string|null the new metadata JSON, or null when there is nothing to change
     */
    private function withHashedToken(string $metadataJson): ?string
    {
        $metadata = json_decode($metadataJson, true);
        if (!is_array($metadata)) {
            return null;
        }

        $token = $metadata[self::TOKEN_KEY] ?? null;
        if (!is_string($token) || $token === '' || preg_match(self::SHA256_HEX, $token) === 1) {
            return null;
        }

        $metadata[self::TOKEN_KEY] = hash('sha256', $token);

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
}

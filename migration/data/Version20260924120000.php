<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * MOL-17 — optimistic concurrency for payment contracts. The shopper's return leg and the PSP webhook
 * both write the contract row; without a version the second writer silently overwrote the first.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OXVERSION to oe_payments_contract (optimistic concurrency, MOL-17)';
    }

    public function up(Schema $schema): void
    {
        // The contract table carries an ENUM column; schema introspection needs the mapping.
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        $table = $schema->getTable('oe_payments_contract');
        if ($table->hasColumn('OXVERSION')) {
            return;
        }
        $table->addColumn('OXVERSION', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
            'comment' => 'Optimistic lock: incremented on every save',
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        $table = $schema->getTable('oe_payments_contract');
        if ($table->hasColumn('OXVERSION')) {
            $table->dropColumn('OXVERSION');
        }
    }
}

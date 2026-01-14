<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Repository\DoctrineContractRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for metadata persistence in DoctrineContractRepository.
 *
 * This test suite verifies that contract metadata (like delivery_address_hash)
 * is correctly persisted to and restored from the database.
 */
class MetadataPersistenceTest extends TestCase
{
    /**
     * Test that metadata is included in the data prepared for database insert/update.
     */
    public function testMetadataIsIncludedInPreparedData(): void
    {
        // Create a contract with metadata
        $contract = new PaymentContract(
            shopId: 1,
            userId: 'user123',
            basketSnapshot: BasketSnapshot::fromArray([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.0,
                'totalVat' => 16.0,
                'currency' => 'EUR',
            ])
        );

        $contract->setMetadata('delivery_address_hash', 'abc123');
        $contract->setMetadata('delivery_address_id', 'addr456');

        // Verify toArray includes metadata
        $contractArray = $contract->toArray();
        $this->assertArrayHasKey('metadata', $contractArray);
        $this->assertEquals('abc123', $contractArray['metadata']['delivery_address_hash']);
        $this->assertEquals('addr456', $contractArray['metadata']['delivery_address_id']);
    }

    /**
     * Test that metadata is restored when loading contract from database.
     *
     * This is the critical test - it verifies the bug fix for metadata
     * not being restored from the database.
     */
    public function testMetadataIsRestoredFromDatabase(): void
    {
        // Arrange: Mock database row with metadata
        $contractId = 'contract_test123';
        $metadataJson = json_encode([
            'delivery_address_hash' => 'hash_from_db',
            'delivery_address_id' => 'addr_from_db',
        ]);

        $databaseRow = [
            'OXID' => $contractId,
            'OXSHOPID' => 1,
            'OXUSERID' => 'user123',
            'OXORDERID' => null,
            'OXSTATE' => 'draft',
            'OXSTATEREASON' => null,
            'OXBASKETDATA' => json_encode([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.0,
                'totalVat' => 16.0,
                'currency' => 'EUR',
            ]),
            'OXTERMS' => null,
            'OXMETADATA' => $metadataJson,
            'OXCONDITIONS' => '[]',
            'OXPROVIDER' => 'stripe',
            'OXPROVIDERORDERID' => 'cs_test123',
            'OXPROVIDERDATA' => null,
            'OXCREATED' => '2025-12-01 14:00:00',
            'OXUPDATED' => '2025-12-01 14:00:00',
            'OXCOMMITTEDAT' => null,
            'OXFULFILLEDAT' => null,
            'OXEXPIRESAT' => '2025-12-02 14:00:00',
        ];

        // Mock connection to return this row
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn($databaseRow);

        $repository = new DoctrineContractRepository($connection);

        // Act: Load contract from database
        $contract = $repository->findById($contractId);

        // Assert: Metadata should be restored
        $this->assertNotNull($contract);
        $this->assertEquals(
            'hash_from_db',
            $contract->getMetadata('delivery_address_hash'),
            'delivery_address_hash should be restored from database'
        );
        $this->assertEquals(
            'addr_from_db',
            $contract->getMetadata('delivery_address_id'),
            'delivery_address_id should be restored from database'
        );

        // Also verify getAllMetadata returns the complete metadata array
        $allMetadata = $contract->getAllMetadata();
        $this->assertCount(2, $allMetadata);
        $this->assertArrayHasKey('delivery_address_hash', $allMetadata);
        $this->assertArrayHasKey('delivery_address_id', $allMetadata);
    }

    /**
     * Test that empty metadata in database results in empty metadata in contract.
     */
    public function testEmptyMetadataIsHandledCorrectly(): void
    {
        $databaseRow = [
            'OXID' => 'contract_empty_meta',
            'OXSHOPID' => 1,
            'OXUSERID' => 'user123',
            'OXORDERID' => null,
            'OXSTATE' => 'draft',
            'OXSTATEREASON' => null,
            'OXBASKETDATA' => json_encode([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.0,
                'totalVat' => 16.0,
                'currency' => 'EUR',
            ]),
            'OXTERMS' => null,
            'OXMETADATA' => '{}',  // Empty JSON object
            'OXCONDITIONS' => '[]',
            'OXPROVIDER' => null,
            'OXPROVIDERORDERID' => null,
            'OXPROVIDERDATA' => null,
            'OXCREATED' => '2025-12-01 14:00:00',
            'OXUPDATED' => '2025-12-01 14:00:00',
            'OXCOMMITTEDAT' => null,
            'OXFULFILLEDAT' => null,
            'OXEXPIRESAT' => null,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($databaseRow);

        $repository = new DoctrineContractRepository($connection);
        $contract = $repository->findById('contract_empty_meta');

        $this->assertNotNull($contract);
        $this->assertNull($contract->getMetadata('delivery_address_hash'));
        $this->assertEmpty($contract->getAllMetadata());
    }

    /**
     * Test that null metadata in database results in empty metadata in contract.
     */
    public function testNullMetadataIsHandledCorrectly(): void
    {
        $databaseRow = [
            'OXID' => 'contract_null_meta',
            'OXSHOPID' => 1,
            'OXUSERID' => 'user123',
            'OXORDERID' => null,
            'OXSTATE' => 'draft',
            'OXSTATEREASON' => null,
            'OXBASKETDATA' => json_encode([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.0,
                'totalVat' => 16.0,
                'currency' => 'EUR',
            ]),
            'OXTERMS' => null,
            'OXMETADATA' => null,  // NULL in database
            'OXCONDITIONS' => '[]',
            'OXPROVIDER' => null,
            'OXPROVIDERORDERID' => null,
            'OXPROVIDERDATA' => null,
            'OXCREATED' => '2025-12-01 14:00:00',
            'OXUPDATED' => '2025-12-01 14:00:00',
            'OXCOMMITTEDAT' => null,
            'OXFULFILLEDAT' => null,
            'OXEXPIRESAT' => null,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($databaseRow);

        $repository = new DoctrineContractRepository($connection);
        $contract = $repository->findById('contract_null_meta');

        $this->assertNotNull($contract);
        $this->assertNull($contract->getMetadata('delivery_address_hash'));
        $this->assertEmpty($contract->getAllMetadata());
    }
}

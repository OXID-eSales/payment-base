<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use OxidEsales\PaymentComponent\Service\Exception\StockReleaseException;
use OxidEsales\PaymentComponent\Service\OxidStockService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Service\OxidStockService
 */
class OxidStockServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private OxidStockService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new OxidStockService($this->connection);
    }

    // =========================================================================
    // reserveForContract tests
    // =========================================================================

    public function testReserveForContractDecrementsStock(): void
    {
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 2],
            ['productId' => 'prod2', 'quantity' => 3],
        ]);

        // Mock available stock
        $this->connection
            ->method('fetchOne')
            ->willReturnCallback(fn($sql, $params) => match ($params['productId']) {
                'prod1' => 10,
                'prod2' => 10,
                default => 0,
            });

        // Expect stock decrements
        $this->connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);

        $this->service->reserveForContract($contract);
    }

    public function testReserveForContractThrowsOnInsufficientStock(): void
    {
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 5],
        ]);

        // Mock insufficient stock
        $this->connection
            ->method('fetchOne')
            ->willReturn(2); // Only 2 available

        $this->expectException(InsufficientStockException::class);

        $this->service->reserveForContract($contract);
    }

    public function testReserveForContractSetsMetadata(): void
    {
        $metadataSet = [];
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 1],
        ]);

        $contract
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $this->connection->method('fetchOne')->willReturn(10);
        $this->connection->method('executeStatement')->willReturn(1);

        $this->service->reserveForContract($contract);

        $this->assertTrue($metadataSet['stock_reserved']);
        $this->assertEquals(['prod1' => 1], $metadataSet['stock_reserved_items']);
    }

    public function testReserveForContractAggregatesSameProduct(): void
    {
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 2],
            ['productId' => 'prod1', 'quantity' => 3], // Same product
        ]);

        $this->connection->method('fetchOne')->willReturn(10);

        // Should only decrement once with quantity 5
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(fn($params) => $params['quantity'] === 5)
            )
            ->willReturn(1);

        $this->service->reserveForContract($contract);
    }

    public function testReserveForContractSkipsEmptyBasket(): void
    {
        $contract = $this->createContractWithItems([]);

        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $this->service->reserveForContract($contract);
    }

    // =========================================================================
    // releaseForContract tests
    // =========================================================================

    public function testReleaseForContractIncrementsStock(): void
    {
        $contract = $this->createContractWithReservedStock([
            'prod1' => 2,
            'prod2' => 3,
        ]);

        // Expect stock increments
        $this->connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);

        $this->service->releaseForContract($contract);
    }

    public function testReleaseForContractSkipsIfNotReserved(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->willReturnCallback(fn($key) => match ($key) {
                'stock_reserved' => false,
                default => null,
            });

        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $this->service->releaseForContract($contract);
    }

    public function testReleaseForContractThrowsOnDatabaseError(): void
    {
        $contract = $this->createContractWithReservedStock(['prod1' => 1]);
        $contract->method('getId')->willReturn('contract123');

        $this->connection
            ->method('executeStatement')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(StockReleaseException::class);

        $this->service->releaseForContract($contract);
    }

    // =========================================================================
    // hasAvailableStock tests
    // =========================================================================

    public function testHasAvailableStockReturnsTrueWhenSufficient(): void
    {
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 5],
        ]);

        $this->connection->method('fetchOne')->willReturn(10);

        $this->assertTrue($this->service->hasAvailableStock($contract));
    }

    public function testHasAvailableStockReturnsFalseWhenInsufficient(): void
    {
        $contract = $this->createContractWithItems([
            ['productId' => 'prod1', 'quantity' => 5],
        ]);

        $this->connection->method('fetchOne')->willReturn(2);

        $this->assertFalse($this->service->hasAvailableStock($contract));
    }

    public function testHasAvailableStockReturnsTrueForEmptyBasket(): void
    {
        $contract = $this->createContractWithItems([]);

        $this->assertTrue($this->service->hasAvailableStock($contract));
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function createContractWithItems(array $items): PaymentContractInterface&MockObject
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => $items,
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getBasketSnapshot')->willReturn($basketSnapshot);

        return $contract;
    }

    /**
     * @param array<string, int> $reservedItems
     */
    private function createContractWithReservedStock(array $reservedItems): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->willReturnCallback(fn($key) => match ($key) {
                'stock_reserved' => true,
                'stock_reserved_items' => $reservedItems,
                default => null,
            });

        return $contract;
    }
}

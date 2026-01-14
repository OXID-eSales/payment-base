<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Service\StockManagementService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OxidEsales\PaymentComponent\Service\StockManagementService
 */
class StockManagementServiceTest extends TestCase
{
    private StockManagementService $service;

    protected function setUp(): void
    {
        $this->service = new StockManagementService();
    }

    public function testReservesStockSuccessfully(): void
    {
        // Arrange
        $productId = 'PROD-001';
        $quantity = 5;

        $this->service->setAvailableStock($productId, 10);

        // Act
        $this->service->reserveStock($productId, $quantity);

        // Assert: Stock is reserved, available stock reduced
        $this->assertFalse($this->service->hasAvailableStock($productId, 6)); // Only 5 left
        $this->assertTrue($this->service->hasAvailableStock($productId, 5)); // Exactly 5 available
    }

    public function testThrowsExceptionWhenInsufficientStock(): void
    {
        // Arrange
        $productId = 'PROD-002';
        $this->service->setAvailableStock($productId, 3);

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for product PROD-002 (requested: 5)');

        // Act
        $this->service->reserveStock($productId, 5);
    }

    public function testReleasesReservedStock(): void
    {
        // Arrange: Reserve 5 units
        $productId = 'PROD-003';
        $this->service->setAvailableStock($productId, 10);
        $this->service->reserveStock($productId, 5);

        // Act: Release 3 units
        $this->service->releaseStock($productId, 3);

        // Assert: 8 units now available (10 - 5 + 3 = 8)
        $this->assertTrue($this->service->hasAvailableStock($productId, 8));
        $this->assertFalse($this->service->hasAvailableStock($productId, 9));
    }

    public function testReleasingAllStockRemovesReservation(): void
    {
        // Arrange
        $productId = 'PROD-004';
        $this->service->setAvailableStock($productId, 10);
        $this->service->reserveStock($productId, 5);

        // Act: Release all reserved stock
        $this->service->releaseStock($productId, 5);

        // Assert: All 10 units available again
        $this->assertTrue($this->service->hasAvailableStock($productId, 10));
    }

    public function testReleasingMoreThanReservedRemovesReservation(): void
    {
        // Arrange
        $productId = 'PROD-005';
        $this->service->setAvailableStock($productId, 10);
        $this->service->reserveStock($productId, 3);

        // Act: Release more than reserved
        $this->service->releaseStock($productId, 5);

        // Assert: All stock available (reservation removed)
        $this->assertTrue($this->service->hasAvailableStock($productId, 10));
    }

    public function testReleaseStockForNonExistentReservationDoesNothing(): void
    {
        // Arrange: No reservation exists
        $productId = 'PROD-006';
        $this->service->setAvailableStock($productId, 10);

        // Act: Release stock that was never reserved (should not throw)
        $this->service->releaseStock($productId, 5);

        // Assert: Stock unchanged
        $this->assertTrue($this->service->hasAvailableStock($productId, 10));
    }

    public function testExpiredReservationsAreCleanedUp(): void
    {
        // Arrange: Reserve with 1 second timeout
        $productId = 'PROD-007';
        $this->service->setAvailableStock($productId, 10);
        $this->service->reserveStock($productId, 5, 1);

        // Assert: Initially reserved
        $this->assertFalse($this->service->hasAvailableStock($productId, 10));

        // Act: Wait for expiration
        sleep(2);

        // Assert: Reservation expired, stock available again
        $this->assertTrue($this->service->hasAvailableStock($productId, 10));
    }

    public function testMultipleReservationsForSameProduct(): void
    {
        // Arrange
        $productId = 'PROD-008';
        $this->service->setAvailableStock($productId, 20);

        // Act: Reserve twice
        $this->service->reserveStock($productId, 5);
        $this->service->reserveStock($productId, 3);

        // Assert: Total 8 units reserved (5 + 3)
        $this->assertTrue($this->service->hasAvailableStock($productId, 12)); // 20 - 8 = 12
        $this->assertFalse($this->service->hasAvailableStock($productId, 13));
    }

    public function testHasAvailableStockReturnsTrueWhenSufficientStock(): void
    {
        // Arrange
        $productId = 'PROD-009';
        $this->service->setAvailableStock($productId, 50);

        // Act & Assert
        $this->assertTrue($this->service->hasAvailableStock($productId, 50));
        $this->assertTrue($this->service->hasAvailableStock($productId, 25));
        $this->assertTrue($this->service->hasAvailableStock($productId, 1));
    }

    public function testHasAvailableStockReturnsFalseWhenInsufficientStock(): void
    {
        // Arrange
        $productId = 'PROD-010';
        $this->service->setAvailableStock($productId, 10);

        // Act & Assert
        $this->assertFalse($this->service->hasAvailableStock($productId, 11));
        $this->assertFalse($this->service->hasAvailableStock($productId, 100));
    }

    public function testDefaultStockIs100WhenNotSet(): void
    {
        // Arrange: Product with no stock explicitly set
        $productId = 'PROD-UNKNOWN';

        // Act & Assert: Default to 100 units
        $this->assertTrue($this->service->hasAvailableStock($productId, 100));
        $this->assertFalse($this->service->hasAvailableStock($productId, 101));
    }

    public function testReservationExpiresAtMaxTimeout(): void
    {
        // Arrange: Two reservations with different timeouts
        $productId = 'PROD-011';
        $this->service->setAvailableStock($productId, 20);

        // Act: First reservation expires in 2 seconds, second in 1 second
        $this->service->reserveStock($productId, 5, 2);
        $this->service->reserveStock($productId, 3, 1);

        // Assert: After 1 second, both still reserved (max timeout used)
        sleep(1);
        $this->assertFalse($this->service->hasAvailableStock($productId, 20));

        // Assert: After 2 seconds total, reservation expires
        sleep(2);
        $this->assertTrue($this->service->hasAvailableStock($productId, 20));
    }

    public function testConcurrentReservationsForDifferentProducts(): void
    {
        // Arrange: Multiple products
        $product1 = 'PROD-012';
        $product2 = 'PROD-013';

        $this->service->setAvailableStock($product1, 10);
        $this->service->setAvailableStock($product2, 15);

        // Act: Reserve different amounts
        $this->service->reserveStock($product1, 5);
        $this->service->reserveStock($product2, 8);

        // Assert: Each product tracked independently
        $this->assertTrue($this->service->hasAvailableStock($product1, 5));
        $this->assertFalse($this->service->hasAvailableStock($product1, 6));

        $this->assertTrue($this->service->hasAvailableStock($product2, 7));
        $this->assertFalse($this->service->hasAvailableStock($product2, 8));
    }
}

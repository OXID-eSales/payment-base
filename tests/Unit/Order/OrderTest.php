<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Order;

use DateTimeInterface;
use OxidEsales\PaymentComponent\Order\Order;
use OxidEsales\PaymentComponent\Order\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Order class.
 *
 * Tests follow TDD principles and verify:
 * - LSP compliance (implements interface correctly)
 * - SRP (only order data, no business logic)
 * - Immutability (except status)
 */
class OrderTest extends TestCase
{
    private Order $order;

    protected function setUp(): void
    {
        $this->order = new Order(
            123,
            '1001',
            'user-456',
            99.99,
            83.99,
            15.99,
            'EUR',
            [
                ['productId' => 'prod1', 'quantity' => 2, 'price' => 49.995],
            ],
            'contract-789'
        );
    }

    /**
     * @test
     * LSP: Order implements OrderInterface correctly
     */
    public function implementsOrderInterface(): void
    {
        $this->assertInstanceOf(OrderInterface::class, $this->order);
    }

    /**
     * @test
     * SRP: Returns correct ID
     */
    public function returnsCorrectId(): void
    {
        $this->assertSame(123, $this->order->getId());
    }

    /**
     * @test
     * SRP: Returns correct order number
     */
    public function returnsCorrectOrderNumber(): void
    {
        $this->assertSame('1001', $this->order->getOrderNumber());
    }

    /**
     * @test
     * SRP: Returns correct user ID
     */
    public function returnsCorrectUserId(): void
    {
        $this->assertSame('user-456', $this->order->getUserId());
    }

    /**
     * @test
     * SRP: Returns correct total gross
     */
    public function returnsCorrectTotalGross(): void
    {
        $this->assertSame(99.99, $this->order->getTotalGross());
    }

    /**
     * @test
     * SRP: Returns correct total net
     */
    public function returnsCorrectTotalNet(): void
    {
        $this->assertSame(83.99, $this->order->getTotalNet());
    }

    /**
     * @test
     * SRP: Returns correct total VAT
     */
    public function returnsCorrectTotalVat(): void
    {
        $this->assertSame(15.99, $this->order->getTotalVat());
    }

    /**
     * @test
     * SRP: Returns correct currency
     */
    public function returnsCorrectCurrency(): void
    {
        $this->assertSame('EUR', $this->order->getCurrency());
    }

    /**
     * @test
     * SRP: Returns correct items
     */
    public function returnsCorrectItems(): void
    {
        $items = $this->order->getItems();

        $this->assertCount(1, $items);
        $this->assertSame('prod1', $items[0]['productId']);
        $this->assertSame(2, $items[0]['quantity']);
    }

    /**
     * @test
     * SRP: Returns correct contract ID
     */
    public function returnsCorrectContractId(): void
    {
        $this->assertSame('contract-789', $this->order->getContractId());
    }

    /**
     * @test
     * SRP: Returns creation timestamp
     */
    public function returnsCreatedAt(): void
    {
        $createdAt = $this->order->getCreatedAt();

        $this->assertInstanceOf(DateTimeInterface::class, $createdAt);
    }

    /**
     * @test
     * SRP: Default status is pending
     */
    public function defaultStatusIsPending(): void
    {
        $this->assertSame('pending', $this->order->getStatus());
    }

    /**
     * @test
     * Status can be updated
     */
    public function statusCanBeUpdated(): void
    {
        $this->order->setStatus('completed');

        $this->assertSame('completed', $this->order->getStatus());
    }

    /**
     * @test
     * Order with empty items array
     */
    public function handlesEmptyItems(): void
    {
        $order = new Order(
            1,
            '1000',
            'user-1',
            0.0,
            0.0,
            0.0,
            'EUR',
            [],
            'contract-1'
        );

        $this->assertSame([], $order->getItems());
    }

    /**
     * @test
     * Order with multiple items
     */
    public function handlesMultipleItems(): void
    {
        $items = [
            ['productId' => 'prod1', 'quantity' => 1, 'price' => 10.0],
            ['productId' => 'prod2', 'quantity' => 2, 'price' => 20.0],
            ['productId' => 'prod3', 'quantity' => 3, 'price' => 30.0],
        ];

        $order = new Order(
            1,
            '1000',
            'user-1',
            150.0,
            126.05,
            23.95,
            'EUR',
            $items,
            'contract-1'
        );

        $this->assertCount(3, $order->getItems());
    }
}

<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Adapter\Response\OrderResponse;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;

/**
 * In-memory implementation of ShopOrderServiceInterface for testing.
 *
 * Creates orders in memory without actual database operations.
 * Useful for integration tests that need to test the full event flow.
 */
class InMemoryShopOrderService implements ShopOrderServiceInterface
{
    private int $nextOrderNumber = 1000;

    /** @var array<string, OrderResponse> */
    private array $orders = [];

    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        $orderId = 'order_' . uniqid();
        $orderNumber = $this->nextOrderNumber++;

        $response = new OrderResponse(
            orderId: $orderId,
            orderNumber: $orderNumber,
            userId: $request->userId,
            totalAmount: 100.0,
            currency: 'EUR',
            status: 'not_finished',
            paymentId: $request->paymentId,
            paymentTransactionId: $request->paymentTransactionId,
            createdAt: new DateTimeImmutable(),
            metadata: $request->metadata,
            shopData: []
        );

        $this->orders[$orderId] = $response;

        return $response;
    }

    /**
     * Get all created orders.
     *
     * @return array<string, OrderResponse>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * Clear all orders.
     */
    public function clear(): void
    {
        $this->orders = [];
        $this->nextOrderNumber = 1000;
    }
}

<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Guard;

use DateInterval;
use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * The attempt guard for headless checkouts, on `oe_payments_idempotency`
 * (created 2025-10-31, unused until now).
 *
 * Key: `order_create:<userId>:<basketId>`. A claim writes an in-flight
 * record that lives for a short window; a second claim inside the window is
 * refused as ORDER_EXISTS, naming the order once there is one. Completing
 * stores the order id; releasing expires the record so the next claim goes
 * through. After the window a new attempt is allowed - that is the shopper
 * coming back to the same basket, and EarlyOrderCreationHandler retires the
 * previous attempt first.
 *
 * A request without a basket id is a session checkout: core's own
 * `sess_challenge` rule applies and this guard stays out of the way.
 *
 * @since 3.0.0
 */
class IdempotentAttemptGuard implements CheckoutAttemptGuardInterface
{
    public const OPERATION = 'order_create';

    public const STATUS_IN_FLIGHT = 'in_flight';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RELEASED = 'released';

    private const WINDOW = 'PT2M';

    public function __construct(private readonly IdempotencyRepositoryInterface $records)
    {
    }

    public function claim(CreateOrderRequest $request): void
    {
        $key = $this->keyFor($request);
        if ($key === null) {
            return;
        }

        $existing = $this->records->findByKey($key);
        if ($existing !== null && $existing->getExpiresAt() > $this->now()) {
            $orderId = $existing->getOrderId();

            throw new ShopOrderException(
                message: 'An order for this checkout attempt already exists',
                errorCode: self::ERROR_ORDER_EXISTS,
                context: [
                    'order_id' => $orderId !== '' ? $orderId : null,
                    'user_id' => $request->userId,
                    'basket_id' => $request->basketId,
                ]
            );
        }

        $this->records->save($this->record($key, '', self::STATUS_IN_FLIGHT, $this->now()->add(new DateInterval(self::WINDOW))));
    }

    public function complete(CreateOrderRequest $request, string $orderId): void
    {
        $key = $this->keyFor($request);
        if ($key === null) {
            return;
        }

        $existing = $this->records->findByKey($key);
        $expiresAt = $existing?->getExpiresAt() ?? $this->now()->add(new DateInterval(self::WINDOW));

        $this->records->save($this->record($key, $orderId, self::STATUS_COMPLETED, $expiresAt));
    }

    public function release(CreateOrderRequest $request): void
    {
        $key = $this->keyFor($request);
        if ($key === null) {
            return;
        }

        // The repository has no delete-by-key; an already-expired record is
        // what claim() treats as "no attempt".
        $this->records->save($this->record($key, '', self::STATUS_RELEASED, $this->now()->sub(new DateInterval('PT1S'))));
    }

    private function keyFor(CreateOrderRequest $request): ?string
    {
        if ($request->basketId === null) {
            return null;
        }

        return self::OPERATION . ':' . $request->userId . ':' . $request->basketId;
    }

    private function record(string $key, string $orderId, string $status, DateTimeImmutable $expiresAt): IdempotencyRecord
    {
        return new IdempotencyRecord(
            id: md5($key),
            key: $key,
            orderId: $orderId,
            operation: self::OPERATION,
            status: $status,
            createdAt: $this->now(),
            expiresAt: $expiresAt
        );
    }

    /**
     * Seam for tests that need a fixed clock.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

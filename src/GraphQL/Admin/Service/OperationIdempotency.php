<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * Idempotency of the admin payment operations on `oe_payments_idempotency` (the table of the headless checkout
 * guard). The record is inserted before the provider is called; the unique index of OXKEY decides which of two
 * simultaneous identical requests runs, the other is told IN_FLIGHT. A finished record is kept for a day:
 * a retry with the same key then reports the earlier success instead of calling the provider again.
 * An in-flight record that outlives its window (a crashed request) is taken over.
 *
 * @since 1.5.0
 */
final class OperationIdempotency implements OperationIdempotencyInterface
{
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RELEASED = 'released';

    private const KEY_PREFIX = 'admin_payment:';
    private const IN_FLIGHT_WINDOW = 'PT2M';
    private const COMPLETED_RETENTION = 'PT24H';

    private readonly Closure $now;

    /**
     * @param Closure(): DateTimeImmutable|null $now the clock; the system clock when none is given
     */
    public function __construct(private readonly IdempotencyRepositoryInterface $records, ?Closure $now = null)
    {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function claim(string $operation, string $orderId, string $discriminator): IdempotencyClaim
    {
        $key = $this->keyFor($operation, $orderId, $discriminator);
        $existing = $this->records->findByKey($key);
        if ($existing !== null && $existing->getExpiresAt() > ($this->now)()) {
            return $this->claimOf($existing->getStatus() === self::STATUS_COMPLETED ? IdempotencyClaim::COMPLETED : IdempotencyClaim::IN_FLIGHT, $key, $operation, $orderId);
        }
        try {
            $this->records->save($this->record($key, $operation, $orderId, self::STATUS_IN_FLIGHT, self::IN_FLIGHT_WINDOW));
        } catch (UniqueConstraintViolationException) {
            return $this->claimOf(IdempotencyClaim::IN_FLIGHT, $key, $operation, $orderId);
        }

        return $this->claimOf(IdempotencyClaim::FRESH, $key, $operation, $orderId);
    }

    public function complete(IdempotencyClaim $claim): void
    {
        $this->records->save($this->record($claim->key, $claim->operation, $claim->orderId, self::STATUS_COMPLETED, self::COMPLETED_RETENTION));
    }

    public function release(IdempotencyClaim $claim): void
    {
        $now = ($this->now)();
        $this->records->save(new IdempotencyRecord(
            md5($claim->key),
            $claim->key,
            $claim->orderId,
            $claim->operation,
            self::STATUS_RELEASED,
            $now,
            $now->sub(new DateInterval('PT1S')),
        ));
    }

    /**
     * Public for the tests that need to name a record; the key is a digest, so a client's text never reaches the column.
     */
    public function keyFor(string $operation, string $orderId, string $discriminator): string
    {
        return self::KEY_PREFIX . hash('sha256', $operation . '|' . $orderId . '|' . $discriminator);
    }

    private function claimOf(string $outcome, string $key, string $operation, string $orderId): IdempotencyClaim
    {
        return new IdempotencyClaim($outcome, $key, $operation, $orderId);
    }

    private function record(string $key, string $operation, string $orderId, string $status, string $lives): IdempotencyRecord
    {
        $now = ($this->now)();

        return new IdempotencyRecord(md5($key), $key, $orderId, $operation, $status, $now, $now->add(new DateInterval($lives)));
    }
}

<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Guard;

use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * `oe_payments_idempotency` as an array, keyed like the table (by id, found
 * by key), so tests can see what the attempt guard recorded.
 */
final class InMemoryIdempotencyRepository implements IdempotencyRepositoryInterface
{
    /** @var array<string, IdempotencyRecord> */
    public array $records = [];

    public int $saves = 0;

    public function save(IdempotencyRecord $record): void
    {
        $this->saves++;
        $this->records[$record->getId()] = $record;
    }

    public function findByKey(string $key): ?IdempotencyRecord
    {
        foreach ($this->records as $record) {
            if ($record->getKey() === $key) {
                return $record;
            }
        }

        return null;
    }

    public function deleteExpired(): int
    {
        return 0;
    }
}
